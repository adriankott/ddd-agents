<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\Base\Entities\MessageHandlers\MessageDispatchCapture;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatementAudit;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * What one compared case of a comparison run collected while it ran, before its outcome is built: the id marks read
 * before it, the harness calls that failed before it, where each harness conversation stood, how many Argus calls the
 * run had made, the dispatch capture and the statement audit of the call, and what the call answered or threw.
 *
 * A plain object of one case of one run; no container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class ToolCallComparisonCaseExecution
{
    /** The case that runs. */
    public ToolCallComparisonToolCall $case;

    /** @var int[] table → its highest id, read before the case's `before` calls */
    public array $highWaterMarksByTable = [];

    /** @var string[] the `before` calls that did not complete, one line each */
    public array $failedBeforeSteps = [];

    /** @var int[] harness conversation name → the index of its newest row before the call */
    public array $newestRowIndexesByConversation = [];

    /** How many Argus calls the run had made before the call. */
    public int $argusCallCountBefore = 0;

    /** Keeps what the call dispatches. */
    public MessageDispatchCapture $dispatchCapture;

    /** Keeps every write statement of the call. */
    public DatabaseWriteStatementAudit $statementAudit;

    /** What the executor answered; null when it threw. */
    public ?ToolResult $toolResult = null;

    /** What the executor threw, as `Class: message`; null when it answered. */
    public ?string $executionError = null;

    /**
     * @param int[] $highWaterMarksByTable table → its highest id
     */
    public function __construct(ToolCallComparisonToolCall $case, array $highWaterMarksByTable)
    {
        $this->case = $case;
        $this->highWaterMarksByTable = $highWaterMarksByTable;
        $this->dispatchCapture = new MessageDispatchCapture();
        $this->statementAudit = new DatabaseWriteStatementAudit();
    }
}
