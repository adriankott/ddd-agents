<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalHarnessToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalToolResultExtraction;
use DDD\Domain\AI\Entities\Evals\AgentEvalToolResultExtractions;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The extractions of a block are held by the argument they read: one extraction per argument key, in the order of the
 * block, found by its argument key.
 */
class AgentEvalToolResultExtractionsTest extends UnitTestCase
{
    public function testTheExtractionsOfABlockNameTheirArgumentInTheOrderOfTheBlock(): void
    {
        $undoCall = AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_UNDO, 'deleteNote');

        $argumentExtractions = $undoCall->buildArgumentExtractions([
            'noteId' => ['path' => 'note.id'],
            'folderId' => ['tool' => 'createNote', 'regex' => 'folder (\d+)'],
        ]);

        self::assertSame(['noteId', 'folderId'], array_map(static fn (AgentEvalToolResultExtraction $extraction): string => $extraction->argumentKey, $argumentExtractions->getElements()));
        self::assertSame('note.id', $argumentExtractions->getByArgumentKey('noteId')?->structuredContentPath);
        self::assertSame('createNote', $argumentExtractions->getByArgumentKey('folderId')?->sourceToolName);
        self::assertNull($argumentExtractions->getByArgumentKey('content'));
        self::assertTrue($undoCall->buildArgumentExtractions('not a map')->isEmpty());
    }

    public function testAnArgumentKeyHoldsOneExtractionAndTheFirstStays(): void
    {
        $firstExtraction = AgentEvalToolResultExtraction::forStructuredContentPath('note.id', 'noteId');
        $secondExtraction = AgentEvalToolResultExtraction::forStructuredContentPath('record.id', 'noteId');
        $argumentExtractions = AgentEvalToolResultExtractions::newInstance();
        self::assertSame(['path' => 'note.id'], $firstExtraction->toArray(), 'the argument key is the key of the block, not part of it');

        $argumentExtractions->add($firstExtraction, $secondExtraction);

        self::assertSame([$firstExtraction], $argumentExtractions->getElements());
        self::assertSame($firstExtraction, $argumentExtractions->getByArgumentKey('noteId'));
    }

    public function testExtractionsThatReadNoArgumentKeepTheKeyOfTheirOwnObject(): void
    {
        $firstFieldExtraction = AgentEvalToolResultExtraction::forStructuredContentPath('note.content');
        $secondFieldExtraction = AgentEvalToolResultExtraction::forStructuredContentPath('note.content');
        $argumentExtractions = AgentEvalToolResultExtractions::newInstance();

        $argumentExtractions->add($firstFieldExtraction, $secondFieldExtraction);

        self::assertSame(2, $argumentExtractions->count());
    }
}
