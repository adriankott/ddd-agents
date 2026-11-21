<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Memories\Curation;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The answer of one model call of the memory curation (extraction or reconciliation): its operations, whether the
 * answer had the expected shape, and the raw text for the logs.
 */
class AgentMemoryCurationResult extends ValueObject
{
    /** @var AgentMemoryOperations The operations of the answer */
    public AgentMemoryOperations $operations;

    /** @var bool False when the answer was empty or had no `operations` list */
    public bool $schemaValid = true;

    /** @var string|null The model's raw answer */
    public ?string $rawResultText = null;

    public function __construct()
    {
        parent::__construct();
        $this->operations = new AgentMemoryOperations();
    }

    /**
     * The result of a model answer: a JSON object with an `operations` list (a code fence around it is removed). An
     * empty or other answer is a result without operations and with {@see $schemaValid} false.
     *
     * @param string|null $resultText
     * @return static
     */
    public static function fromModelJson(?string $resultText): static
    {
        $agentMemoryCurationResult = new static();
        $agentMemoryCurationResult->rawResultText = $resultText;
        if ($resultText === null || trim($resultText) === '') {
            $agentMemoryCurationResult->schemaValid = false;
            return $agentMemoryCurationResult;
        }
        $decoded = json_decode(static::stripCodeFence($resultText), true);
        if (!is_array($decoded) || !isset($decoded['operations']) || !is_array($decoded['operations'])) {
            $agentMemoryCurationResult->schemaValid = false;
            return $agentMemoryCurationResult;
        }
        foreach ($decoded['operations'] as $operationEntry) {
            if (is_array($operationEntry)) {
                $agentMemoryOperation = AgentMemoryOperation::fromDecodedEntry($operationEntry);
                $agentMemoryCurationResult->operations->add($agentMemoryOperation);
            }
        }
        return $agentMemoryCurationResult;
    }

    /**
     * Adds the operations of `$agentMemoryCurationResult` to this result; the shape stays valid only if both were.
     *
     * @param AgentMemoryCurationResult $agentMemoryCurationResult
     * @return void
     */
    public function mergeResult(AgentMemoryCurationResult $agentMemoryCurationResult): void
    {
        foreach ($agentMemoryCurationResult->operations->getElements() as $agentMemoryOperation) {
            $this->operations->add($agentMemoryOperation);
        }
        $this->schemaValid = $this->schemaValid && $agentMemoryCurationResult->schemaValid;
    }

    protected static function stripCodeFence(string $resultText): string
    {
        $trimmed = trim($resultText);
        $trimmed = (string)preg_replace('/^```[a-zA-Z]*\s*/', '', $trimmed);
        $trimmed = (string)preg_replace('/\s*```$/', '', $trimmed);
        return trim($trimmed);
    }
}
