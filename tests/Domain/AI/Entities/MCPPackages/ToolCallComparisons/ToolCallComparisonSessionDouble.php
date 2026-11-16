<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonHostRecordingInterface;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonSession;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonToolCall;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseIdsAboveHighWaterMarks;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatementAudit;
use RuntimeException;

/**
 * The session with a fake agent space of the run (prepared and released as lifecycle events), the database reads of
 * the id marks replaced by values a test hands in, statement audits that are never installed (a test feeds their
 * statements), and an executor that answers every call itself: it records what it was asked to do.
 */
class ToolCallComparisonSessionDouble extends ToolCallComparisonSession
{
    /** The placeholder of the second harness row the fake preparation creates */
    public const string SECOND_HARNESS_ROW_PLACEHOLDER = '<comparison:owner>';

    /** The id the fake preparation names the run's agent space by */
    public int $preparedAgentSpaceId = 0;

    /** The id of a second harness row the fake preparation creates with the space */
    public int $preparedSecondRowId = 0;

    /** Whether the account check refuses the run */
    public bool $refusesTheAccount = false;

    /** Whether the check of the script's references refuses the run (after the preparation) */
    public bool $refusesTheScriptReferences = false;

    /** @var string[] What the double was asked to do, in order */
    public array $lifecycleEvents = [];

    /** @var string[] The tools the executor double ran */
    public array $executedToolNames = [];

    /** How often the ids above the marks were read */
    public int $mintedIdReadCount = 0;

    /** How often a call started from a fresh process state */
    public int $workerStateResetCount = 0;

    /** Whether the executor double answers every call with an error */
    public bool $toolAnswersError = false;

    /** What the read of the ids above the marks answers; null = nothing */
    public ?DatabaseIdsAboveHighWaterMarks $idsAboveHighWaterMarks = null;

    /** The audit the next harness step or preparation gets (a test feeds its statements); null = an empty one */
    public ?DatabaseWriteStatementAudit $statementAuditOfNextCall = null;

    /** @var string[] The application's own opaque token keys */
    public array $applicationOpaqueTokenKeys = [];

    /** The application's recorder the run installs; none by default */
    public ?ToolCallComparisonHostRecordingInterface $hostRecordingToInstall = null;

    protected function buildHostRecording(): ?ToolCallComparisonHostRecordingInterface
    {
        return $this->hostRecordingToInstall;
    }

    protected function resetWorkerStateBeforeCall(): void
    {
        $this->workerStateResetCount++;
    }

    protected function refuseAnAccountOtherThanTheComparisonAccount(): void
    {
        if ($this->refusesTheAccount) {
            throw new RuntimeException('the comparison runs only as the comparison account');
        }
    }

    protected function prepareComparisonAgentSpace(): void
    {
        $this->lifecycleEvents[] = 'prepare';
        $this->comparisonAgentSpaceId = $this->preparedAgentSpaceId;
        $this->declareHarnessId($this->comparisonAgentSpaceId, $this->getComparisonAgentSpacePlaceholder());
        if ($this->preparedSecondRowId > 0) {
            $this->declareHarnessId($this->preparedSecondRowId, self::SECOND_HARNESS_ROW_PLACEHOLDER);
        }
    }

    protected function releaseComparisonAgentSpace(): void
    {
        if ($this->comparisonAgentSpaceId > 0) {
            $this->lifecycleEvents[] = 'release ' . $this->comparisonAgentSpaceId;
            $this->comparisonAgentSpaceId = 0;
        }
        parent::releaseComparisonAgentSpace();
    }

    protected function refuseUnsafeScriptReferences(): void
    {
        if ($this->refusesTheScriptReferences) {
            throw new RuntimeException('the script names a record that exists');
        }
    }

    protected function readIdHighWaterMarks(): array
    {
        return [];
    }

    protected function findIdsAboveHighWaterMarks(array $highWaterMarksByTable): DatabaseIdsAboveHighWaterMarks
    {
        $this->mintedIdReadCount++;
        return $this->idsAboveHighWaterMarks ?? new DatabaseIdsAboveHighWaterMarks();
    }

    protected function startStatementAudit(): DatabaseWriteStatementAudit
    {
        $databaseWriteStatementAudit = $this->statementAuditOfNextCall ?? new DatabaseWriteStatementAudit();
        $this->statementAuditOfNextCall = null;
        return $databaseWriteStatementAudit;
    }

    protected function executeToolCall(ToolCallComparisonToolCall $toolCall): ToolResult
    {
        $this->executedToolNames[] = $toolCall->toolName;
        return new ToolResult('{"content":[{"type":"text","text":"ok"}]}', $this->toolAnswersError);
    }

    protected function findApplicationOpaqueTokenKeys(): array
    {
        return $this->applicationOpaqueTokenKeys;
    }
}
