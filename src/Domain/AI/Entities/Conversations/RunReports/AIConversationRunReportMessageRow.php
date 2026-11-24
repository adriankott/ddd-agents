<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\RunReports;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetrics;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One message row of a run as the metrics read it ({@see \DDD\Domain\AI\Services\Conversations\Metrics\AIConversationMetricsService::findMessageRowsForConversation()}):
 * the row's fields under their short keys (`i` index, `t` type, `c` created, `snap` model call, `dur`, `tool`, `args`,
 * `cost`, `variants`, `skillSections`, …), kept as the statement returned them, and the plan snapshot the message
 * stamped ({@see AIConversationRunReportTaskPlanSnapshot}).
 *
 * @phpstan-consistent-constructor
 */
class AIConversationRunReportMessageRow extends ValueObject
{
    /** @var array The row's fields under their short keys, as the metrics statement returned them */
    protected array $rowFields = [];

    /** @var AIConversationRunReportTaskPlanSnapshot|null The plan snapshot the message stamped; null when none or not read */
    public ?AIConversationRunReportTaskPlanSnapshot $taskPlanSnapshot = null;

    /**
     * The row of the fields `$rowFields`. A row that carries its snapshot under `taskPlanSnapshot` (the JSON text of
     * the column or its decoded array) keeps it as its snapshot.
     *
     * @param array $rowFields the metric row
     * @return static
     */
    public static function fromRowFields(array $rowFields): static
    {
        $aIConversationRunReportMessageRow = static::newInstance();
        $aIConversationRunReportMessageRow->rowFields = $rowFields;
        $aIConversationRunReportMessageRow->taskPlanSnapshot = AIConversationRunReportTaskPlanSnapshot::fromColumnValue(
            $aIConversationRunReportMessageRow->getMessageIndex(),
            $rowFields['taskPlanSnapshot'] ?? null
        );
        return $aIConversationRunReportMessageRow;
    }

    /** `i`: the index of the message in its conversation; -1 when the row names none. */
    public function getMessageIndex(): int
    {
        return (int)($this->rowFields['i'] ?? -1);
    }

    /** `i` as the variants timeline counts it: 0 when the row names none. */
    public function getMessageIndexOrZero(): int
    {
        return (int)($this->rowFields['i'] ?? 0);
    }

    /** Whether the row is a tool call (`t`). */
    public function isToolCall(): bool
    {
        return ($this->rowFields['t'] ?? '') === AIConversationMessage::MESSAGE_TOOL_CALL;
    }

    /** `tool`: the called tool; empty when the row names none. */
    public function getToolName(): string
    {
        return (string)($this->rowFields['tool'] ?? '');
    }

    /** `dur`: the seconds of the tool call; 0 when not measured. */
    public function getDurationSeconds(): float
    {
        return (float)($this->rowFields['dur'] ?? 0);
    }

    /** `args`: the arguments of the tool call as stored; empty when none. */
    public function getArgumentsText(): string
    {
        return (string)($this->rowFields['args'] ?? '');
    }

    /** Whether the row is a model call: it carries a request snapshot (`snap`). */
    public function isModelCall(): bool
    {
        return in_array($this->rowFields['snap'] ?? null, [1, '1', true], true);
    }

    /** `cost`: the cost of the row's model call in USD; 0 when none. */
    public function getCostInUSD(): float
    {
        return (float)($this->rowFields['cost'] ?? 0);
    }

    /** `inTok`: the input tokens of the row's model call (cached and written ones included); 0 when none. */
    public function getInputTokens(): int
    {
        return (int)($this->rowFields['inTok'] ?? 0);
    }

    /** `cachedTok`: the input tokens the row's model call read from the prompt cache; 0 when none. */
    public function getCachedInputTokens(): int
    {
        return (int)($this->rowFields['cachedTok'] ?? 0);
    }

    /** `writeTok`: the input tokens the row's model call wrote to the prompt cache; 0 when none. */
    public function getCacheWriteTokens(): int
    {
        return (int)($this->rowFields['writeTok'] ?? 0);
    }

    /** `outTok`: the output tokens of the row's model call (reasoning included); 0 when none. */
    public function getOutputTokens(): int
    {
        return (int)($this->rowFields['outTok'] ?? 0);
    }

    /** `reasoningTok`: the reasoning (thinking) tokens the provider reported for the row's model call; 0 when it stored none. */
    public function getReasoningTokens(): int
    {
        return is_numeric($this->rowFields['reasoningTok'] ?? null) ? (int)$this->rowFields['reasoningTok'] : 0;
    }

    /** `inputTransformations`: how many thinking blocks the provider dropped from the request of the row's model call. */
    public function countDroppedThinkingBlocks(): int
    {
        $inputTransformationsJson = $this->rowFields['inputTransformations'] ?? null;
        return is_string($inputTransformationsJson) ? substr_count($inputTransformationsJson, '"thinking_dropped"') : 0;
    }

    /** `variants`: the variants vector the row's model call ran with, as JSON text; null when the row carries none. */
    public function findVariantsJson(): ?string
    {
        $variantsJson = $this->rowFields['variants'] ?? null;
        return is_string($variantsJson) && $variantsJson !== '' && $variantsJson !== 'null' ? $variantsJson : null;
    }

    /** `variants` as stored (JSON text or null), for the vector decoding. */
    public function getVariantsValue(): mixed
    {
        return $this->rowFields['variants'] ?? null;
    }

    /** `skillSections` as stored (JSON text or null). */
    public function getSkillSectionsValue(): mixed
    {
        return $this->rowFields['skillSections'] ?? null;
    }

    /** `c`: the creation time, `Y-m-d H:i:s` (fractions cut); empty when the row carries no time. */
    public function getCreatedAtText(): string
    {
        $createdText = substr((string)($this->rowFields['c'] ?? ''), 0, 19);
        // the text `0` carries no time
        return $createdText === '0' ? '' : $createdText;
    }

    /** Whether the metrics count the row as a refused or failed tool call (the metrics class decides). */
    public function isFailedToolCallOf(AIConversationMetrics $metrics): bool
    {
        return $metrics::isFailedToolCallRow($this->rowFields);
    }

    /**
     * The row's fields as the metrics read them ({@see AIConversationMetrics::buildFromRows()}).
     *
     * @return array
     */
    public function toArray(): array
    {
        return $this->rowFields;
    }
}
