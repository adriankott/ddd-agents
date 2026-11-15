<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\TurnEvents\AIConversationTurnEvent;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\FindWidgetsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The skill-preload VERDICT of one evaluated run per owner turn: what the decision model picked and whether that was
 * the skill the case expected, beside what the chat model loaded first on its own. No I/O.
 *
 * Owner turn here: a ROOT row that is the owner's TEXT or a legacy user-role TEXT
 * ({@see AIConversationMessage::isOwnerOrLegacyUserRoleText()}) — a narrower test than the turn initiator of
 * {@see AgentEvalRunFigures}: a signal that starts a turn opens no owner turn here.
 *
 * Built in four steps: collect the decision model's preload outcomes per owner message, find the chat model's first
 * load per owner turn, judge each owner turn, total the verdicts.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSkillPreloadSummary extends ValueObject
{
    /** @var string `expect.preloadSkills` entry of a turn for which no skill is to be preloaded */
    public const string EXPECTED_PRELOAD_NONE = 'none';

    /** @var string Verdict: the turn carries no expectation */
    public const string VERDICT_NO_EXPECTATION = 'noExpectation';

    /** @var string Verdict: the decision model was not asked in this turn */
    public const string VERDICT_NOT_ASKED = 'notAsked';

    /** @var string Verdict: the preload matched the expectation */
    public const string VERDICT_HIT = 'hit';

    /** @var string Verdict: a skill was preloaded that the turn did not expect */
    public const string VERDICT_WRONG_PICK = 'wrongPick';

    /** @var string Prefix of the verdict of a turn in which nothing was preloaded although a skill was expected */
    public const string VERDICT_SKIP_PREFIX = 'skip:';

    /**
     * @var array<int, array{turn: int, ownerMessageIndex: int, expected: string, pick: string, applied: bool, pickedByRerank: bool, skipReason: string, pickConfidence: mixed, pickMargin: mixed, modelFirstLoad: string, subSkillPicks: string[], subSkillsApplied: string[], verdict: string}>
     * One row per owner turn, in turn order
     */
    public array $turns = [];

    /** @var int Judged turns whose verdict is a hit */
    public int $hits = 0;

    /** @var int Judged turns whose verdict is a wrong pick */
    public int $wrongPicks = 0;

    /** @var int Judged turns in which nothing was preloaded although a skill was expected */
    public int $skips = 0;

    /** @var int Judged turns in which the decision model was not asked */
    public int $notAsked = 0;

    /** @var int Turns that carry an expectation */
    public int $judged = 0;

    /** @var int Judged turns whose first skill the chat model loaded on its own was an expected one */
    public int $modelFirstLoadHits = 0;

    /**
     * The verdict of every owner turn of the conversation.
     *
     * @param string[] $expectedSkillsByTurn the expected skill per owner turn ('' = no expectation for that turn;
     *        `A|B` = either skill satisfies the turn; {@see EXPECTED_PRELOAD_NONE} = nothing is to be preloaded)
     */
    public static function fromConversation(AIConversation $conversation, array $expectedSkillsByTurn): static
    {
        $agentEvalSkillPreloadSummary = static::newInstance();
        $preloadOutcomesByOwnerMessageIndex = $agentEvalSkillPreloadSummary->collectPreloadOutcomesByOwnerMessageIndex($conversation);
        $modelFirstLoadsByTurn = $agentEvalSkillPreloadSummary->collectModelFirstLoadsByTurn($conversation->messages);
        foreach ($agentEvalSkillPreloadSummary->collectOwnerMessageIndexesByTurn($conversation->messages) as $turn => $ownerMessageIndex) {
            $agentEvalSkillPreloadSummary->turns[] = $agentEvalSkillPreloadSummary->judgeTurn(
                $turn,
                $ownerMessageIndex,
                trim((string)($expectedSkillsByTurn[$turn] ?? '')),
                $preloadOutcomesByOwnerMessageIndex[$ownerMessageIndex] ?? [],
                $modelFirstLoadsByTurn[$turn] ?? ''
            );
        }
        $agentEvalSkillPreloadSummary->totalVerdicts();
        return $agentEvalSkillPreloadSummary;
    }

    /**
     * The summary with the keys a case row stores under `skillPreload`.
     *
     * @return array{turns: list<array<string, mixed>>, hits: int, wrongPicks: int, skips: int, notAsked: int, judged: int, modelFirstLoadHits: int}
     */
    public function toArray(): array
    {
        return [
            'turns' => $this->turns,
            'hits' => $this->hits,
            'wrongPicks' => $this->wrongPicks,
            'skips' => $this->skips,
            'notAsked' => $this->notAsked,
            'judged' => $this->judged,
            'modelFirstLoadHits' => $this->modelFirstLoadHits,
        ];
    }

    /**
     * The outcomes of the skill-preload site, by the owner message index they were decided for. The rerank pass is
     * left out: it writes its verdict into the record of the pass it reranked.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    protected function collectPreloadOutcomesByOwnerMessageIndex(AIConversation $conversation): array
    {
        $preloadOutcomesByOwnerMessageIndex = [];
        foreach ($conversation->getAgentWorkingState()->decisions ?? [] as $decisionRecord) {
            if (($decisionRecord->site ?? null) !== AgentDecisionModelSettings::SITE_SKILL_PRELOAD || !is_array($decisionRecord->outcome)) {
                continue;
            }
            if (($decisionRecord->outcome['subject'] ?? null) === AIConversationTurnEvent::SUBJECT_SKILL_PRELOAD_RERANK) {
                continue;
            }
            $preloadOutcomesByOwnerMessageIndex[(int)($decisionRecord->ownerMessageIndex ?? -1)][] = $decisionRecord->outcome;
        }
        return $preloadOutcomesByOwnerMessageIndex;
    }

    /**
     * The chat model's own first skill load per owner turn: the first name of its first `load_skills` call, or the
     * first skill a `findWidgets` call loaded with the widget it found, whichever comes first.
     *
     * @return array<int, string> skill name by turn position
     */
    protected function collectModelFirstLoadsByTurn(AIConversationMessages $rootMessages): array
    {
        $modelFirstLoadsByTurn = [];
        $currentTurn = -1;
        foreach ($rootMessages->getElements() as $message) {
            if ($message->isOwnerOrLegacyUserRoleText()) {
                $currentTurn++;
                continue;
            }
            if (($message->messageType ?? null) !== AIConversationMessage::MESSAGE_TOOL_CALL || !isset($message->toolCall) || isset($modelFirstLoadsByTurn[$currentTurn])) {
                continue;
            }
            if (($message->toolCall->name ?? null) === LoadSkillsTool::NAME) {
                $loadedSkillNames = (array)($message->toolCall->arguments['skill_names'] ?? []);
                $modelFirstLoadsByTurn[$currentTurn] = (string)($loadedSkillNames[0] ?? '');
                continue;
            }
            if (($message->toolCall->name ?? null) === FindWidgetsTool::NAME && $message->toolCall->skillNamesLoadedByTheCall !== []) {
                $modelFirstLoadsByTurn[$currentTurn] = (string)$message->toolCall->skillNamesLoadedByTheCall[0];
            }
        }
        return $modelFirstLoadsByTurn;
    }

    /**
     * The message index of every owner turn's owner row, by turn position.
     *
     * @return int[]
     */
    protected function collectOwnerMessageIndexesByTurn(AIConversationMessages $rootMessages): array
    {
        $ownerMessageIndexesByTurn = [];
        foreach ($rootMessages->getElements() as $message) {
            if ($message->isOwnerOrLegacyUserRoleText()) {
                $ownerMessageIndexesByTurn[] = (int)($message->indexInConversation ?? -1);
            }
        }
        return $ownerMessageIndexesByTurn;
    }

    /**
     * The row of one owner turn. The first pass (the root skills) is the outcome without a root skill it was unlocked
     * by; every other outcome is a sub-skill pass.
     *
     * @param array<int, array<string, mixed>> $preloadOutcomes the outcomes decided for this turn's owner message
     * @return array{turn: int, ownerMessageIndex: int, expected: string, pick: string, applied: bool, pickedByRerank: bool, skipReason: string, pickConfidence: mixed, pickMargin: mixed, modelFirstLoad: string, subSkillPicks: string[], subSkillsApplied: string[], verdict: string}
     */
    protected function judgeTurn(int $turn, int $ownerMessageIndex, string $expected, array $preloadOutcomes, string $modelFirstLoad): array
    {
        $rootOutcome = null;
        $subSkillPicks = [];
        $subSkillsApplied = [];
        foreach ($preloadOutcomes as $preloadOutcome) {
            if (($preloadOutcome['unlockedByRootSkillName'] ?? null) === null) {
                $rootOutcome ??= $preloadOutcome;
                continue;
            }
            if (($preloadOutcome['pick'] ?? null) !== null) {
                $subSkillPicks[] = (string)$preloadOutcome['pick'];
                if (($preloadOutcome['applied'] ?? false) === true) {
                    $subSkillsApplied[] = (string)$preloadOutcome['pick'];
                }
            }
        }
        $pick = is_array($rootOutcome) ? (string)($rootOutcome['pick'] ?? '') : '';
        $applied = is_array($rootOutcome) && ($rootOutcome['applied'] ?? false) === true;
        $skipReason = is_array($rootOutcome) ? (string)($rootOutcome['skipReason'] ?? '') : '';
        return [
            'turn' => $turn,
            'ownerMessageIndex' => $ownerMessageIndex,
            'expected' => $expected,
            'pick' => $pick,
            'applied' => $applied,
            'pickedByRerank' => is_array($rootOutcome) && ($rootOutcome['pickedByRerank'] ?? false) === true,
            'skipReason' => $skipReason,
            'pickConfidence' => is_array($rootOutcome) ? ($rootOutcome['pickConfidence'] ?? null) : null,
            'pickMargin' => is_array($rootOutcome) ? ($rootOutcome['pickMargin'] ?? null) : null,
            'modelFirstLoad' => $modelFirstLoad,
            'subSkillPicks' => array_values(array_unique($subSkillPicks)),
            'subSkillsApplied' => array_values(array_unique($subSkillsApplied)),
            'verdict' => $this->buildVerdict($expected, $rootOutcome !== null, $pick, $applied, $skipReason),
        ];
    }

    /** The verdict of one owner turn against its expectation. */
    protected function buildVerdict(string $expected, bool $decisionModelWasAsked, string $pick, bool $applied, string $skipReason): string
    {
        if ($expected === '') {
            return static::VERDICT_NO_EXPECTATION;
        }
        $expectedAlternatives = $this->getExpectedAlternatives($expected);
        if (!$decisionModelWasAsked) {
            return static::VERDICT_NOT_ASKED;
        }
        if (in_array(static::EXPECTED_PRELOAD_NONE, $expectedAlternatives, true)) {
            // the turn is to load NOTHING (a greeting, thanks, the capability question): a preload is the defect
            return $applied ? static::VERDICT_WRONG_PICK : static::VERDICT_HIT;
        }
        if ($applied) {
            return in_array($pick, $expectedAlternatives, true) ? static::VERDICT_HIT : static::VERDICT_WRONG_PICK;
        }
        $pickNote = '';
        if ($pick !== '') {
            $pickNote = in_array($pick, $expectedAlternatives, true) ? ' (pick was right)' : ' (pick ' . $pick . ')';
        }
        return static::VERDICT_SKIP_PREFIX . ($skipReason !== '' ? $skipReason : 'unknown') . $pickNote;
    }

    /**
     * The skills that satisfy a turn: `A|B` = either one.
     *
     * @return string[]
     */
    protected function getExpectedAlternatives(string $expected): array
    {
        return $expected !== '' ? array_map('trim', explode('|', $expected)) : [];
    }

    /** The totals over the judged turns. */
    protected function totalVerdicts(): void
    {
        foreach ($this->turns as $turnRow) {
            if ($turnRow['expected'] === '') {
                continue;
            }
            $this->judged++;
            $verdict = $turnRow['verdict'];
            if ($verdict === static::VERDICT_HIT) {
                $this->hits++;
            } elseif ($verdict === static::VERDICT_WRONG_PICK) {
                $this->wrongPicks++;
            } elseif ($verdict === static::VERDICT_NOT_ASKED) {
                $this->notAsked++;
            } elseif (str_starts_with($verdict, static::VERDICT_SKIP_PREFIX)) {
                $this->skips++;
            }
            if (in_array($turnRow['modelFirstLoad'], $this->getExpectedAlternatives($turnRow['expected']), true)) {
                $this->modelFirstLoadHits++;
            }
        }
    }
}
