<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLine;
use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityLines;
use DDD\Domain\AI\Entities\Conversations\Agent\Activity\AgentActivityTurnFeed;
use DDD\Domain\AI\Entities\Voice\Prompts\AgentVoiceAIPromptNames;
use DDD\Domain\AI\Entities\Voice\Prompts\AgentVoicePromptText;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the caller hears while ONE delegated task of a voice call runs: the session worker asks this schedule on every
 * fast check, with the turn's activity feed, the clock and the speech clock, and sends what it decides: a spoken
 * progress sentence from the newest activity line, the other new lines as unspoken context, or the generic "still
 * working" bridge when no new line arrived for a while. One schedule per task: the worker starts a new one with every
 * delegated task and drops it at the hand-over, so the caps count per task.
 *
 * No cache, no clock of its own, no session. The caller keeps the rules this object cannot see: nothing after an armed
 * goodbye or a farewell, nothing once the task's answer was handed over.
 *
 * The rules:
 * - the first spoken update at the earliest {@see FIRST_SPOKEN_PROGRESS_AFTER_MILLISECONDS} after the delegation, then
 *   at most one per {@see MINIMUM_MILLISECONDS_BETWEEN_SPOKEN_PROGRESS}, at most {@see MAXIMUM_SPOKEN_PROGRESS_PER_TASK}
 *   per task;
 * - spoken only when nobody spoke for {@see QUIET_MILLISECONDS_BEFORE_SPEAKING} (the provider's event channel reports
 *   no speaking state: the last transcript fragment of either side is the clock);
 * - several new lines in one window: the newest is spoken, the older ones go as unspoken context; once the spoken cap
 *   is reached every new line goes as unspoken context at once;
 * - a step that has no line yet goes as unspoken context in the step's own words, once;
 * - no new line for {@see BRIDGE_AFTER_MILLISECONDS_WITHOUT_NEW_LINE} (and nobody speaks): the bridge, at most
 *   {@see MAXIMUM_BRIDGES_PER_TASK} per task.
 *
 * Override point of an application's subclass at the same path: the prompt of a step's own words
 * ({@see getStepWithoutLinePromptName()}).
 */
class AgentVoiceTaskProgressSchedule extends ValueObject
{
    /** @var int Milliseconds after the delegation before the first progress sentence may be spoken. */
    public const int FIRST_SPOKEN_PROGRESS_AFTER_MILLISECONDS = 6000;

    /** @var int Milliseconds between two spoken progress sentences, at least. */
    public const int MINIMUM_MILLISECONDS_BETWEEN_SPOKEN_PROGRESS = 10000;

    /** @var int Spoken progress sentences per task, at most. */
    public const int MAXIMUM_SPOKEN_PROGRESS_PER_TASK = 3;

    /** @var int Milliseconds without a transcript fragment from either side before anything is spoken. */
    public const int QUIET_MILLISECONDS_BEFORE_SPEAKING = 2000;

    /** @var int Milliseconds without a new line (since the delegation, the last line or the last bridge) before the bridge. */
    public const int BRIDGE_AFTER_MILLISECONDS_WITHOUT_NEW_LINE = 12000;

    /** @var int Bridges per task, at most. */
    public const int MAXIMUM_BRIDGES_PER_TASK = 2;

    /** @var int Unix milliseconds the task was delegated — every clock of this schedule starts here. */
    public int $delegatedAtUnixMs = 0;

    /** @var string[] {@see AgentActivityTurnFeed::buildElementKey()} keys of the lines already spoken or passed as context. */
    public array $handledLineElementKeys = [];

    /** @var string[] Element keys of the steps whose own words were already passed as context (their line may still come). */
    public array $handledStepElementKeys = [];

    /** @var int Progress sentences spoken for this task. */
    public int $spokenProgressCount = 0;

    /** @var int|null Unix milliseconds the last progress sentence was spoken; null = none yet. */
    public ?int $lastSpokenProgressAtUnixMs = null;

    /** @var int|null Unix milliseconds the last line was spoken or passed as context — the bridge clock. */
    public ?int $lastLinePassedOnAtUnixMs = null;

    /** @var int Bridges sent for this task. */
    public int $bridgeCount = 0;

    /** @var int|null Unix milliseconds of the last bridge. */
    public ?int $lastBridgeAtUnixMs = null;

    /** @var int|null Milliseconds from the delegation to the first narrated line in the feed; null until one was seen. */
    public ?int $firstLineSeenAfterMilliseconds = null;

    public static function startForDelegationAt(int $delegatedAtUnixMs): static
    {
        $agentVoiceTaskProgressSchedule = new static();
        $agentVoiceTaskProgressSchedule->delegatedAtUnixMs = $delegatedAtUnixMs;
        return $agentVoiceTaskProgressSchedule;
    }

    /**
     * Decide what goes to the voice model now and record it as sent. `$turnFeed` is the task's feed (null while nothing
     * is narrated); `$lastSpeechAtUnixMs` is the last transcript fragment of either side (null = nobody spoke yet).
     *
     * The update names the line to speak (or none), the unspoken context (older new lines, the words of steps without
     * a line), whether the generic bridge is due, and, on the ONE decision that saw the task's first narrated line,
     * the milliseconds since the delegation.
     */
    public function decideUpdateAt(?AgentActivityTurnFeed $turnFeed, int $nowUnixMs, ?int $lastSpeechAtUnixMs): AgentVoiceTaskProgressUpdate
    {
        $newNarratedLines = $turnFeed?->getNarratedLinesNotYetHandled($this->handledLineElementKeys) ?? [];
        $firstLineSeenAfterMilliseconds = null;
        if ($newNarratedLines !== [] && $this->firstLineSeenAfterMilliseconds === null) {
            $this->firstLineSeenAfterMilliseconds = max(0, $nowUnixMs - $this->delegatedAtUnixMs);
            $firstLineSeenAfterMilliseconds = $this->firstLineSeenAfterMilliseconds;
        }

        $spokenProgressLine = null;
        $silentContextLines = [];
        if ($newNarratedLines !== []) {
            if ($this->spokenProgressCount >= static::MAXIMUM_SPOKEN_PROGRESS_PER_TASK) {
                $silentContextLines = $this->passLinesOn(static::buildActivityLines(...$newNarratedLines), $nowUnixMs);
            } elseif ($this->isSpokenProgressDueAt($nowUnixMs, $lastSpeechAtUnixMs)) {
                $newestNarratedLine = array_pop($newNarratedLines);
                $silentContextLines = $this->passLinesOn(static::buildActivityLines(...$newNarratedLines), $nowUnixMs);
                $this->passLinesOn(static::buildActivityLines($newestNarratedLine), $nowUnixMs);
                $spokenProgressLine = (string)$newestNarratedLine->line;
                $this->spokenProgressCount++;
                $this->lastSpokenProgressAtUnixMs = $nowUnixMs;
            }
            // otherwise the new lines wait for the next due moment: the newest is spoken then, the rest as context
        }

        $stepsWithoutLine = $turnFeed?->getStepsWithoutLineNotYetHandled(
            array_merge($this->handledLineElementKeys, $this->handledStepElementKeys)
        ) ?? [];
        foreach ($stepsWithoutLine as $stepWithoutLine) {
            $this->handledStepElementKeys[] = AgentActivityTurnFeed::buildElementKey($stepWithoutLine);
            $stepWords = static::buildStepWords($stepWithoutLine);
            if ($stepWords !== '') {
                $silentContextLines[] = $stepWords;
            }
        }

        $bridgeDue = $spokenProgressLine === null && $this->isBridgeDueAt($nowUnixMs, $lastSpeechAtUnixMs);
        if ($bridgeDue) {
            $this->bridgeCount++;
            $this->lastBridgeAtUnixMs = $nowUnixMs;
        }

        $agentVoiceTaskProgressUpdate = new AgentVoiceTaskProgressUpdate();
        $agentVoiceTaskProgressUpdate->spokenProgressLine = $spokenProgressLine;
        $agentVoiceTaskProgressUpdate->silentContextLines = $silentContextLines;
        $agentVoiceTaskProgressUpdate->bridgeDue = $bridgeDue;
        $agentVoiceTaskProgressUpdate->firstLineSeenAfterMilliseconds = $firstLineSeenAfterMilliseconds;

        return $agentVoiceTaskProgressUpdate;
    }

    /** Whether a progress sentence may be spoken now: late enough, not too soon after the last one, under the cap, quiet. */
    public function isSpokenProgressDueAt(int $nowUnixMs, ?int $lastSpeechAtUnixMs): bool
    {
        return $this->spokenProgressCount < static::MAXIMUM_SPOKEN_PROGRESS_PER_TASK
            && $nowUnixMs - $this->delegatedAtUnixMs >= static::FIRST_SPOKEN_PROGRESS_AFTER_MILLISECONDS
            && ($this->lastSpokenProgressAtUnixMs === null
                || $nowUnixMs - $this->lastSpokenProgressAtUnixMs >= static::MINIMUM_MILLISECONDS_BETWEEN_SPOKEN_PROGRESS)
            && static::isQuietAt($nowUnixMs, $lastSpeechAtUnixMs);
    }

    /** Whether the bridge is due: no new line for the bridge interval, under the cap, quiet. */
    public function isBridgeDueAt(int $nowUnixMs, ?int $lastSpeechAtUnixMs): bool
    {
        $lastNewsAtUnixMs = max(
            $this->delegatedAtUnixMs,
            $this->lastLinePassedOnAtUnixMs ?? 0,
            $this->lastBridgeAtUnixMs ?? 0
        );
        return $this->bridgeCount < static::MAXIMUM_BRIDGES_PER_TASK
            && $nowUnixMs - $lastNewsAtUnixMs >= static::BRIDGE_AFTER_MILLISECONDS_WITHOUT_NEW_LINE
            && static::isQuietAt($nowUnixMs, $lastSpeechAtUnixMs);
    }

    /** Nobody spoke for {@see QUIET_MILLISECONDS_BEFORE_SPEAKING}, or nobody spoke at all yet. */
    public static function isQuietAt(int $nowUnixMs, ?int $lastSpeechAtUnixMs): bool
    {
        return $lastSpeechAtUnixMs === null || $nowUnixMs - $lastSpeechAtUnixMs >= static::QUIET_MILLISECONDS_BEFORE_SPEAKING;
    }

    /**
     * A step without a line, in its own words for the unspoken context: the tool name split into words
     * ("getWebPageAsMarkdown" → "Working on: get web page as markdown."); empty for an element without a tool name.
     */
    public static function buildStepWords(AgentActivityLine $activityLine): string
    {
        $toolName = trim($activityLine->narratorToolName);
        if ($toolName === '') {
            return '';
        }
        $spacedToolName = (string)preg_replace('/(?<=[a-z0-9])(?=[A-Z])|[_\-.]+/', ' ', $toolName);

        return AgentVoicePromptText::forName(
            static::getStepWithoutLinePromptName(),
            ['STEP_WORDS' => mb_strtolower(trim((string)preg_replace('/\s+/', ' ', $spacedToolName)))]
        );
    }

    /** The prompt of a step's own words. */
    protected static function getStepWithoutLinePromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_STEP_WITHOUT_LINE;
    }

    /** The given lines of one turn's feed as a set, in their order. */
    protected static function buildActivityLines(AgentActivityLine ...$activityLines): AgentActivityLines
    {
        $activityLinesSet = new AgentActivityLines();
        foreach ($activityLines as $activityLine) {
            $activityLinesSet->add($activityLine);
        }

        return $activityLinesSet;
    }

    /**
     * Mark lines as handled and return their sentences.
     *
     * @return string[]
     */
    protected function passLinesOn(AgentActivityLines $activityLines, int $nowUnixMs): array
    {
        $lineSentences = [];
        foreach ($activityLines->getElements() as $activityLine) {
            $this->handledLineElementKeys[] = AgentActivityTurnFeed::buildElementKey($activityLine);
            $lineSentences[] = (string)$activityLine->line;
            $this->lastLinePassedOnAtUnixMs = $nowUnixMs;
        }
        return $lineSentences;
    }
}
