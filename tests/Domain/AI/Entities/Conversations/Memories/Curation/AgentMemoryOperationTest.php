<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Memories\Curation;

use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryCurationResult;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryOperation;
use DDD\Tests\Agents\Support\UnitTestCase;

/** Reading the curation model's answer: one operation per entry, tolerant of case, labels and a code fence. */
class AgentMemoryOperationTest extends UnitTestCase
{
    public function testAnEntryIsReadWithUpperCaseCodesAndTheRefLabelAsIndex(): void
    {
        $agentMemoryOperation = AgentMemoryOperation::fromDecodedEntry([
            'operation' => ' invalidate ',
            'type' => 'fact',
            'subject' => 'business',
            'content' => '  has 3 cargo bikes ',
            'targetMemoryRef' => 'REF_2',
            'targetEcho' => 'has 4 cargo',
            'resolvesConflict' => true,
            'sourceMessageId' => '17',
        ]);

        self::assertSame(AgentMemoryOperation::OPERATION_INVALIDATE, $agentMemoryOperation->operation);
        self::assertSame('FACT', $agentMemoryOperation->memoryType);
        self::assertSame('BUSINESS', $agentMemoryOperation->subject);
        self::assertSame('has 3 cargo bikes', $agentMemoryOperation->content);
        self::assertSame(2, $agentMemoryOperation->targetMemoryRef);
        self::assertTrue($agentMemoryOperation->resolvesConflict);
        self::assertSame(17, $agentMemoryOperation->sourceMessageId);
        self::assertTrue($agentMemoryOperation->changesStore());
        self::assertFalse($agentMemoryOperation->requiresClarification());
    }

    public function testEmptyValuesAndANonPositiveSourceMessageIdAreNull(): void
    {
        $agentMemoryOperation = AgentMemoryOperation::fromDecodedEntry(['operation' => 'NOOP', 'content' => ' ', 'sourceMessageId' => 0, 'resolvesConflict' => 'true']);

        self::assertNull($agentMemoryOperation->content);
        self::assertNull($agentMemoryOperation->sourceMessageId);
        self::assertFalse($agentMemoryOperation->resolvesConflict);
        self::assertFalse($agentMemoryOperation->changesStore());
        self::assertFalse($agentMemoryOperation->hasContent());
    }

    public function testAnAnswerInACodeFenceIsReadAndTwoEqualOperationsAreBothKept(): void
    {
        $agentMemoryCurationResult = AgentMemoryCurationResult::fromModelJson(
            "```json\n{\"operations\":[{\"operation\":\"INVALIDATE\",\"content\":\"reply in Spanish\",\"targetMemoryRef\":\"REF_0\"},"
            . "{\"operation\":\"INVALIDATE\",\"content\":\"reply in Spanish\",\"targetMemoryRef\":\"REF_0\"}]}\n```"
        );

        self::assertTrue($agentMemoryCurationResult->schemaValid);
        self::assertSame(2, $agentMemoryCurationResult->operations->count());
    }

    public function testAnEmptyOrShapelessAnswerIsInvalid(): void
    {
        self::assertFalse(AgentMemoryCurationResult::fromModelJson(null)->schemaValid);
        self::assertFalse(AgentMemoryCurationResult::fromModelJson('  ')->schemaValid);
        self::assertFalse(AgentMemoryCurationResult::fromModelJson('{"surfaced": []}')->schemaValid);
        self::assertTrue(AgentMemoryCurationResult::fromModelJson('{"operations": []}')->schemaValid);
    }

    public function testMergingKeepsAllOperationsAndInvalidatesOnAnInvalidPart(): void
    {
        $mergedResult = AgentMemoryCurationResult::fromModelJson('{"operations":[{"operation":"ADD","content":"a"}]}');
        $mergedResult->mergeResult(AgentMemoryCurationResult::fromModelJson('{"operations":[{"operation":"ADD","content":"b"}]}'));
        self::assertSame(2, $mergedResult->operations->count());
        self::assertTrue($mergedResult->schemaValid);

        $mergedResult->mergeResult(AgentMemoryCurationResult::fromModelJson(null));
        self::assertFalse($mergedResult->schemaValid);
    }
}
