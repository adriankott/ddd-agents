<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeReport;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSettings;
use DDD\Domain\AI\Entities\Evals\SeedAccounts\AgentEvalSeedAccountResetReport;
use DDD\Domain\AI\Services\Eval\AgentEvalSeedAccountResetService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Tests\Agents\Domain\AI\Entities\Evals\SeedAccounts\AgentEvalSeedAccountResetReportOfAnApplication;

/**
 * The clean start without a database, as an application that removes leftover drafts before the purge and notes after
 * it: the evaluation accounts, the runs in flight, the purge and the application's steps are answered from properties,
 * and every step is recorded in the order it ran.
 */
class AgentEvalSeedAccountResetServiceDouble extends AgentEvalSeedAccountResetService
{
    public const string STEP_BEFORE_PURGE = 'beforePurge';

    public const string STEP_PURGE = 'purge';

    public const string STEP_AFTER_PURGE = 'afterPurge';

    /** @var int[] */
    public array $evalAccountIds = [7001, 7002];

    /** @var int[] one answer per runs-in-flight check; an empty list answers 0 */
    public array $runsInFlightAnswers = [];

    /** @var int[] */
    public array $pauses = [];

    /** @var string[] the steps in the order they ran */
    public array $stepsRun = [];

    public ?AIConversationEvalPurgeSettings $purgeSettings = null;

    public ?AgentSpace $seedAgentSpaceSeenBeforePurge = null;

    /** @var int[] the agent spaces the application keeps */
    public array $keptAgentSpaceIds = [];

    /** @var int[] the drafts the application deletes before the purge */
    public array $leftoverDraftIds = [];

    public int $notesToWipe = 0;

    /** @var bool False = the module's own report and no step of an application */
    public bool $actsAsApplication = true;

    public int $purgedTrees = 3;

    public int $treesSkippedRunInFlight = 1;

    public int $treesSkippedFreshHeartbeat = 0;

    /** @var int[] rows per step label */
    public array $purgedRowsByStep = ['EntityAIConversationMessages' => 100, 'EntityAIConversations' => 20];

    public int $clockUnixTimestamp = 1767225600;

    protected function newResetReport(): AgentEvalSeedAccountResetReport
    {
        return $this->actsAsApplication ? AgentEvalSeedAccountResetReportOfAnApplication::newInstance() : parent::newResetReport();
    }

    protected function findConfiguredEvalAccountIds(): array
    {
        return $this->evalAccountIds;
    }

    protected function countRunsInFlightOfAccount(Account $account): int
    {
        return array_shift($this->runsInFlightAnswers) ?? 0;
    }

    protected function pauseSeconds(int $seconds): void
    {
        $this->pauses[] = $seconds;
    }

    protected function currentUnixTimestamp(): int
    {
        return $this->clockUnixTimestamp;
    }

    protected function findKeptAgentSpaceIds(): array
    {
        return $this->keptAgentSpaceIds;
    }

    protected function resetHostAccountStateBeforePurge(Account $account, ?AgentSpace $seedAgentSpace, AgentEvalSeedAccountResetReport $agentEvalSeedAccountResetReport): void
    {
        if (!$this->actsAsApplication || !$agentEvalSeedAccountResetReport instanceof AgentEvalSeedAccountResetReportOfAnApplication) {
            parent::resetHostAccountStateBeforePurge($account, $seedAgentSpace, $agentEvalSeedAccountResetReport);
            return;
        }
        $this->stepsRun[] = static::STEP_BEFORE_PURGE;
        $this->seedAgentSpaceSeenBeforePurge = $seedAgentSpace;
        $agentEvalSeedAccountResetReport->deletedDraftIds = $this->leftoverDraftIds;
    }

    protected function resetHostAccountStateAfterPurge(Account $account, AgentEvalSeedAccountResetReport $agentEvalSeedAccountResetReport): void
    {
        if (!$this->actsAsApplication || !$agentEvalSeedAccountResetReport instanceof AgentEvalSeedAccountResetReportOfAnApplication) {
            parent::resetHostAccountStateAfterPurge($account, $agentEvalSeedAccountResetReport);
            return;
        }
        $this->stepsRun[] = static::STEP_AFTER_PURGE;
        $agentEvalSeedAccountResetReport->wipedNotes = $this->notesToWipe;
    }

    protected function runConversationPurge(AIConversationEvalPurgeSettings $aIConversationEvalPurgeSettings): AIConversationEvalPurgeReport
    {
        $this->stepsRun[] = static::STEP_PURGE;
        $this->purgeSettings = $aIConversationEvalPurgeSettings;
        $aIConversationEvalPurgeReport = AIConversationEvalPurgeReport::forRun($aIConversationEvalPurgeSettings->dryRun);
        $aIConversationEvalPurgeReport->treesPurged = $this->purgedTrees;
        $aIConversationEvalPurgeReport->treesSkippedRunInFlight = $this->treesSkippedRunInFlight;
        $aIConversationEvalPurgeReport->treesSkippedFreshHeartbeat = $this->treesSkippedFreshHeartbeat;
        $aIConversationEvalPurgeReport->rowsByStep = $this->purgedRowsByStep;
        return $aIConversationEvalPurgeReport;
    }
}
