<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalHarnessToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalToolResultExtraction;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The evaluation harness decodes a stored tool result on its own, not through the stored call's decoders: the log
 * text of a removal (the FIRST content part's text, untrimmed), the "record still there" check
 * (`structuredContent.deleted === false`, strictly) and the capture by path (no trailing-hint fallback). Each answer
 * is pinned for every shape a stored result takes: the MCP envelope, plain text, a row with a hint after its JSON
 * object, rendered text with preserved facts, garbage, the empty and the unset result. Odd answers are marked "quirk:".
 */
class AgentEvalToolResultDecodersCharacterizationTest extends UnitTestCase
{
    /**
     * Every stored shape by name (null = the result is unset).
     *
     * @return array<string, string|null>
     */
    protected static function fixtures(): array
    {
        return [
            'plainText' => "  Saved the opening hours.  \nNEXT: ask the owner about holidays.\n",
            'mcpJson' => '{"content":[{"type":"text","text":"  Profile saved.  "},{"type":"image","data":"QUJD"},{"type":"text","text":"Second part\n"}],'
                . '"structuredContent":{"saved":true,"memoryId":42,"identityResolution":{"confidence":"high"},"entryId":"r-9","siteUrl":"https:\/\/example.org"},"isError":false}',
            'legacyJsonWithHint' => '{"content":[{"type":"text","text":"Pages written."}],"structuredContent":{"delegationTasks":["Write page home"],"outcome":"done","saved":true},"isError":false}'
                . "\nGATE SATISFIED — tick the item.",
            'preservedFacts' => '{"content":[{"type":"text","text":"## Keywords\n- example keyword (https:\/\/example.org\/keywords)"}],"isError":false,'
                . '"preservedFacts":{"keywords.elements.count":8,"outcome":"RENDERED","saved":"yes"}}',
            'preservedFactsEmpty' => '{"content":[{"type":"text","text":"Read."}],"isError":false,"preservedFacts":[]}',
            'outcomeIncomplete' => '{"content":[{"type":"text","text":"NOTHING was saved: 1 place unresolved."}],"structuredContent":{"outcome":"incomplete","saved":false},"isError":false}',
            'appliedFalse' => '{"content":[{"type":"text","text":"Rejected: duplicate_revision"}],"structuredContent":{"applied":false,"reason":"duplicate_revision"},"isError":false}',
            'turnObligationPresent' => '{"content":[{"type":"text","text":"Build blocked."}],"structuredContent":{"turnObligation":"  Rebuild before you answer.  "},"isError":false}',
            'turnObligationEmpty' => '{"content":[{"type":"text","text":"Build done."}],"structuredContent":{"turnObligation":""},"isError":false}',
            'backgroundOperations' => '{"content":[{"type":"text","text":"Saved."}],"structuredContent":{"backgroundOperations":['
                . '{"operation":" refreshIndex ","toolName":null,"toolArguments":{"url":"https:\/\/example.org"},"skillNames":["Example.Index",7]},'
                . '{"operation":"executeTool","toolName":"getGoals","toolArguments":"none"}'
                . ']},"isError":false,"preservedFacts":{"saved":"yes"}}',
            'backgroundOperationsOnly' => '{"content":[{"type":"text","text":"Saved, the crawl runs."}],"structuredContent":{"backgroundOperations":[{"operation":"refreshIndex"}]},"isError":false}',
            'delegationKeys' => '{"content":[{"type":"text","text":"3 pages pending."}],"structuredContent":{'
                . '"delegationTasks":["Write page home","   ",42],"delegationMaxStepsPerTurn":500,"turnMinimumSteps":9},"isError":false}',
            'garbage' => '{oops: this is not json} 502 Bad Gateway',
            'emptyString' => '',
            'null' => null,
        ];
    }

    /** A removal by the harness with the stored result recorded as an error answer. */
    protected function removalWithResult(?string $storedResult, bool $resultIsError): AgentEvalHarnessToolCall
    {
        $removalCall = AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_UNDO, 'deleteNote', [], true);
        $removalCall->recordResult((string)$storedResult, $resultIsError);
        return $removalCall;
    }

    public function testTheHarnessDecodersOfEveryShape(): void
    {
        $prefix = '(nothing left to remove, or refused) ';
        $expectedRemovalLines = array_fill_keys(array_keys(self::fixtures()), $prefix . 'the tool answered an error');
        // quirk: the FIRST content part, untrimmed
        $expectedRemovalLines['mcpJson'] = $prefix . '  Profile saved.  ';
        $expectedRemovalLines['preservedFacts'] = $prefix . "## Keywords\n- example keyword (https://example.org/keywords)";
        $expectedRemovalLines['preservedFactsEmpty'] = $prefix . 'Read.';
        $expectedRemovalLines['outcomeIncomplete'] = $prefix . 'NOTHING was saved: 1 place unresolved.';
        $expectedRemovalLines['appliedFalse'] = $prefix . 'Rejected: duplicate_revision';
        $expectedRemovalLines['turnObligationPresent'] = $prefix . 'Build blocked.';
        $expectedRemovalLines['turnObligationEmpty'] = $prefix . 'Build done.';
        $expectedRemovalLines['backgroundOperations'] = $prefix . 'Saved.';
        $expectedRemovalLines['backgroundOperationsOnly'] = $prefix . 'Saved, the crawl runs.';
        $expectedRemovalLines['delegationKeys'] = $prefix . '3 pages pending.';
        $expectedCapturedSaved = array_fill_keys(array_keys(self::fixtures()), null);
        $expectedCapturedSaved['mcpJson'] = true;
        $expectedCapturedSaved['outcomeIncomplete'] = false;
        $captureOfSaved = AgentEvalToolResultExtraction::fromExtractionBlock(['path' => 'saved']);
        $removalLines = [];
        $leftTheObject = [];
        $capturedSaved = [];
        foreach (self::fixtures() as $fixtureName => $storedResult) {
            $removalLines[$fixtureName] = $this->removalWithResult($storedResult, true)->describeRemovalResult();
            $leftTheObject[$fixtureName] = $this->removalWithResult($storedResult, true)->isRemovalThatLeftTheObject();
            $capturedSaved[$fixtureName] = $captureOfSaved->resolveValueFromToolResult((string)$storedResult);
        }
        self::assertSame($expectedRemovalLines, $removalLines);
        self::assertSame(array_fill_keys(array_keys(self::fixtures()), false), $leftTheObject);
        // quirk: no trailing-hint fallback for the capture — a hinted row's structured value is not captured by path
        self::assertSame($expectedCapturedSaved, $capturedSaved);
        self::assertSame('raw', $this->removalWithResult('raw', false)->describeRemovalResult(), 'no error: the content as is');
        self::assertTrue($this->removalWithResult('{"structuredContent":{"deleted":false}}', false)->isRemovalThatLeftTheObject());
        self::assertFalse($this->removalWithResult('{"structuredContent":{"deleted":0}}', false)->isRemovalThatLeftTheObject(), 'strict false');
    }
}
