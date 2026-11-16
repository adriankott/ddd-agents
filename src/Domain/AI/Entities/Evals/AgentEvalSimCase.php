<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One case of a judged run: a simulated owner (the driver model) chats with the agent toward a goal, then the judge
 * model reads the trace and returns a verdict.
 *
 * The generic keys have typed accessors here: `goal`, `openingPrompt`, `userFacts` (what the simulated owner knows),
 * `successRubric`, `maxTurns`, `followUpPrompts` and `followUpIdleMinutes` (scripted owner turns before the driver
 * takes over), `purposeName`, `reportChannel`, `scheduledRun`, `compactionParamsText` (the compaction parameters as the
 * driver and the judge of a compaction-tree run read them) and the three settings blocks `runSettings`,
 * `contextRetentionSettings` and `compactionSettings`, which the case applies to its conversation itself. Every other
 * key is an application's own: it is kept unchanged and read with {@see getApplicationValue()}; {@see toArray()} gives
 * back the case exactly as it was built.
 *
 * Nothing is refused when the case is built: a missing text reads as empty, a missing number as the runner's default,
 * a settings block that is no object is ignored.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSimCase extends ValueObject
{
    /** @var array<string, mixed> The case as it was decoded */
    protected array $caseFields = [];

    /**
     * The case of the decoded object `$caseFields`.
     *
     * @param array<string, mixed> $caseFields
     * @return static
     */
    public static function fromArray(array $caseFields): static
    {
        $agentEvalSimCase = static::newInstance();
        $agentEvalSimCase->caseFields = $caseFields;
        return $agentEvalSimCase;
    }

    /**
     * The judged case of a tools case (one fixture serves both flavors): the case's `prompt` is the opening owner
     * turn; `goal`, `userFacts` and `successRubric` are the case's own, else the default texts `$agentEvalJudgedCaseTexts`
     * ({@see AgentEvalJudgedCaseTexts::forCase()}); `maxTurns`, the flat `maxStepsPerTurn` (as
     * `runSettings.maxStepsPerTurn`), `compactionSettings`, `reportChannel`, `scheduledRun`, `purposeName` and the
     * scripted owner turns (`followUpPrompts`, `followUpIdleMinutes`) ride through in the judged run's form. Every other
     * key of the case (its scope, its tool block, `restore`, an application's own fixture keys) is kept unchanged
     * behind the judged keys, which win.
     */
    public static function fromEvaluatedCase(AgentEvalCase $agentEvalCase, AgentEvalJudgedCaseTexts $agentEvalJudgedCaseTexts): static
    {
        $caseFields = $agentEvalCase->toArray();
        $judgedFields = [
            'goal' => (string)($caseFields['goal'] ?? $agentEvalJudgedCaseTexts->goal),
            'openingPrompt' => $agentEvalCase->getPrompt(),
            'userFacts' => (string)($caseFields['userFacts'] ?? $agentEvalJudgedCaseTexts->ownerFacts),
            'successRubric' => (string)($caseFields['successRubric'] ?? $agentEvalJudgedCaseTexts->successRubric),
        ];
        if (isset($caseFields['maxTurns'])) {
            $judgedFields['maxTurns'] = (int)$caseFields['maxTurns'];
        }
        if (isset($caseFields['maxStepsPerTurn'])) {
            $judgedFields['runSettings']['maxStepsPerTurn'] = (int)$caseFields['maxStepsPerTurn'];
        }
        if (is_array($caseFields['compactionSettings'] ?? null)) {
            $judgedFields['compactionSettings'] = $caseFields['compactionSettings'];
        }
        if (isset($caseFields['reportChannel'])) {
            $judgedFields['reportChannel'] = (string)$caseFields['reportChannel'];
        }
        if ((bool)($caseFields['scheduledRun'] ?? false)) {
            $judgedFields['scheduledRun'] = true;
        }
        if (isset($caseFields['purposeName'])) {
            $judgedFields['purposeName'] = (string)$caseFields['purposeName'];
        }
        if (is_array($caseFields['followUpPrompts'] ?? null) && $caseFields['followUpPrompts'] !== []) {
            $judgedFields['followUpPrompts'] = array_values(array_map('strval', $caseFields['followUpPrompts']));
            if (is_array($caseFields['followUpIdleMinutes'] ?? null)) {
                $judgedFields['followUpIdleMinutes'] = array_values(array_map('intval', $caseFields['followUpIdleMinutes']));
            }
        }
        return static::fromArray($judgedFields + $caseFields);
    }

    /**
     * The case exactly as it was built, unknown keys included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->caseFields;
    }

    /** Whether the case carries the key `$key` (a null value counts as carried). */
    public function hasKey(string $key): bool
    {
        return array_key_exists($key, $this->caseFields);
    }

    /**
     * The value of a key of the case as it was decoded — for an application's own key (the module reads its generic
     * keys through the accessors below). Null when the case does not carry the key.
     */
    public function getApplicationValue(string $key): mixed
    {
        return $this->caseFields[$key] ?? null;
    }

    /** `goal`: what the simulated owner wants; empty when the case names none. */
    public function getGoal(): string
    {
        return (string)($this->caseFields['goal'] ?? '');
    }

    /** `openingPrompt`: the first owner message; empty when the case names none. */
    public function getOpeningPrompt(): string
    {
        return (string)($this->caseFields['openingPrompt'] ?? '');
    }

    /** `userFacts`: what the simulated owner knows and answers from; empty when the case names none. */
    public function getOwnerFacts(): string
    {
        return (string)($this->caseFields['userFacts'] ?? '');
    }

    /** `successRubric`: what the judge checks; empty when the case names none. */
    public function getSuccessRubric(): string
    {
        return (string)($this->caseFields['successRubric'] ?? '');
    }

    /** `compactionParamsText`: the compaction parameters as text for the driver and the judge; empty when absent. */
    public function getCompactionParamsText(): string
    {
        return (string)($this->caseFields['compactionParamsText'] ?? '');
    }

    /** `maxTurns`: the most owner turns, at least 1; `$defaultMaxTurns` when the case names none. */
    public function resolveMaxTurns(int $defaultMaxTurns): int
    {
        return isset($this->caseFields['maxTurns']) ? max(1, (int)$this->caseFields['maxTurns']) : $defaultMaxTurns;
    }

    /**
     * `followUpPrompts`: the scripted owner messages after the first turn, in order, trimmed, blank ones dropped.
     *
     * @return string[]
     */
    public function getFollowUpPrompts(): array
    {
        $trimmedFollowUpPrompts = array_map('trim', array_map('strval', (array)($this->caseFields['followUpPrompts'] ?? [])));
        return array_values(array_filter($trimmedFollowUpPrompts, 'strlen'));
    }

    /**
     * `followUpIdleMinutes`: how long the owner was away before each scripted follow-up, aligned with
     * {@see getFollowUpPrompts()}; a missing entry means no idle time.
     *
     * @return int[]
     */
    public function getFollowUpIdleMinutes(): array
    {
        return array_values(array_map('intval', (array)($this->caseFields['followUpIdleMinutes'] ?? [])));
    }

    /** `purposeName`: the purpose the case's conversation runs in, as text whenever the key is set; null otherwise. */
    public function getPurposeName(): ?string
    {
        return isset($this->caseFields['purposeName']) ? (string)$this->caseFields['purposeName'] : null;
    }

    /**
     * `reportChannel`: the channel an unattended run reports on, as text whenever the key is set; null for a run the
     * owner attends.
     */
    public function getReportChannel(): ?string
    {
        return isset($this->caseFields['reportChannel']) ? (string)$this->caseFields['reportChannel'] : null;
    }

    /** `scheduledRun`: true = the case replays one firing of a task schedule; any truthy value counts. */
    public function isScheduledRun(): bool
    {
        return (bool)($this->caseFields['scheduledRun'] ?? false);
    }

    /**
     * Merges the case's `runSettings` (`maxStepsPerTurn`, `maxTurnCostUsd`) into the conversation's run settings: what
     * the purpose or the creation already stamped stays. Nothing happens when the case has no such block.
     */
    public function applyRunSettingsTo(AIConversation $conversation): void
    {
        $runSettingsFields = $this->getSettingsBlock('runSettings');
        if ($runSettingsFields === null) {
            return;
        }
        $runSettings = isset($conversation->runSettings) ? $conversation->runSettings : RunSettings::newInstance();
        if (isset($runSettingsFields['maxStepsPerTurn'])) {
            $runSettings->maxStepsPerTurn = (int)$runSettingsFields['maxStepsPerTurn'];
        }
        if (isset($runSettingsFields['maxTurnCostUsd'])) {
            $runSettings->maxTurnCostUsd = (float)$runSettingsFields['maxTurnCostUsd'];
        }
        $conversation->runSettings = $runSettings;
    }

    /**
     * Merges the case's `contextRetentionSettings` (`storeRequestSnapshot`, `hintRecentToolCallsToDiscourageRepeats`,
     * `maxToolCallImagesOnWire`) into the conversation's settings — never replaces them: the purpose stamps its own
     * retention policy at creation, and a fresh object would put the run on the global defaults. Nothing happens when
     * the case has no such block.
     */
    public function applyContextRetentionSettingsTo(AIConversation $conversation): void
    {
        $contextRetentionSettingsFields = $this->getSettingsBlock('contextRetentionSettings');
        if ($contextRetentionSettingsFields === null) {
            return;
        }
        $contextRetentionSettings = isset($conversation->contextRetentionSettings)
            ? $conversation->contextRetentionSettings
            : ContextRetentionSettings::newInstance();
        if (isset($contextRetentionSettingsFields['storeRequestSnapshot'])) {
            $contextRetentionSettings->storeRequestSnapshot = (bool)$contextRetentionSettingsFields['storeRequestSnapshot'];
        }
        if (isset($contextRetentionSettingsFields['hintRecentToolCallsToDiscourageRepeats'])) {
            $contextRetentionSettings->hintRecentToolCallsToDiscourageRepeats = (bool)$contextRetentionSettingsFields['hintRecentToolCallsToDiscourageRepeats'];
        }
        if (isset($contextRetentionSettingsFields['maxToolCallImagesOnWire'])) {
            $contextRetentionSettings->maxToolCallImagesOnWire = (int)$contextRetentionSettingsFields['maxToolCallImagesOnWire'];
        }
        $conversation->contextRetentionSettings = $contextRetentionSettings;
    }

    /**
     * Gives the conversation FRESH compaction settings built from the case's `compactionSettings` (`enabled`,
     * `triggerTokens`, `keepRecentTurns`, `resummarizeFromSourceEvery`, `aiModelName`, `keepRecentMessages`,
     * `segmentMessages`, `tierFanIn`, `levelRetention`); a key the case leaves out stays unset. A case with low values
     * makes the conversation compact within its few turns. Nothing happens when the case has no such block.
     */
    public function applyCompactionSettingsTo(AIConversation $conversation): void
    {
        $compactionSettingsFields = $this->getSettingsBlock('compactionSettings');
        if ($compactionSettingsFields === null) {
            return;
        }
        $compactionSettings = CompactionSettings::newInstance();
        if (isset($compactionSettingsFields['enabled'])) {
            $compactionSettings->enabled = (bool)$compactionSettingsFields['enabled'];
        }
        if (isset($compactionSettingsFields['triggerTokens'])) {
            $compactionSettings->triggerTokens = (int)$compactionSettingsFields['triggerTokens'];
        }
        if (isset($compactionSettingsFields['keepRecentTurns'])) {
            $compactionSettings->keepRecentTurns = (int)$compactionSettingsFields['keepRecentTurns'];
        }
        if (isset($compactionSettingsFields['resummarizeFromSourceEvery'])) {
            $compactionSettings->resummarizeFromSourceEvery = (int)$compactionSettingsFields['resummarizeFromSourceEvery'];
        }
        if (isset($compactionSettingsFields['aiModelName'])) {
            $compactionSettings->aiModelName = (string)$compactionSettingsFields['aiModelName'];
        }
        $this->applyCompactionTreeFieldsTo($compactionSettings, $compactionSettingsFields);
        $conversation->compactionSettings = $compactionSettings;
    }

    /**
     * The tree fields of the case's `compactionSettings` (`keepRecentMessages`, `segmentMessages`, `tierFanIn`,
     * `levelRetention`) on `$compactionSettings`.
     *
     * @param CompactionSettings $compactionSettings
     * @param array<string, mixed> $compactionSettingsFields
     * @return void
     */
    protected function applyCompactionTreeFieldsTo(CompactionSettings $compactionSettings, array $compactionSettingsFields): void
    {
        if (isset($compactionSettingsFields['keepRecentMessages'])) {
            $compactionSettings->keepRecentMessages = (int)$compactionSettingsFields['keepRecentMessages'];
        }
        if (isset($compactionSettingsFields['segmentMessages'])) {
            $compactionSettings->segmentMessages = (int)$compactionSettingsFields['segmentMessages'];
        }
        if (isset($compactionSettingsFields['tierFanIn'])) {
            $compactionSettings->tierFanIn = (int)$compactionSettingsFields['tierFanIn'];
        }
        if (isset($compactionSettingsFields['levelRetention'])) {
            $compactionSettings->levelRetention = (int)$compactionSettingsFields['levelRetention'];
        }
    }

    /**
     * A settings block of the case; null when the case has none or it is no object.
     *
     * @return array<string, mixed>|null
     */
    protected function getSettingsBlock(string $blockKey): ?array
    {
        return is_array($this->caseFields[$blockKey] ?? null) ? $this->caseFields[$blockKey] : null;
    }
}
