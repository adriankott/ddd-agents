<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonScript;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonToolCall;
use DDD\Tests\Agents\Support\UnitTestCase;
use RuntimeException;

/**
 * A comparison script: its fields, the labels of its harness calls, the placeholder of the run's agent space in the
 * arguments, the checks every script gets and the application's own keys and rules, in their order.
 */
class ToolCallComparisonScriptTest extends UnitTestCase
{
    protected static function buildScriptJson(): string
    {
        return (string)json_encode([
            'package' => 'ComparisonNotesMCPPackage',
            'agentSpaceId' => 42,
            'spaceTemplate' => 'notes',
            'opaqueTokenKeys' => ['idempotencyKey'],
            'conversations' => ['chat' => ['ownerRows' => ['Hello']], 'plan' => ['purposeName' => 'Plan', 'agentWorkingState' => ['taskPlan' => []]], 'child' => ['childOf' => 'plan']],
            'fixture' => [['tool' => 'recordComparisonNote', 'arguments' => ['note' => 'warm up'], 'conversation' => 'chat']],
            'cases' => [
                ['id' => 'record', 'tool' => 'recordComparisonNote', 'arguments' => ['agentSpaceId' => '{{agentSpaceId}}', 'nested' => ['agentSpaceId' => '{{agentSpaceId}}']], 'conversation' => 'chat',
                    'before' => [['tool' => 'recordComparisonNote', 'conversation' => 'chat'], ['tool' => 'recordComparisonNote', 'conversation' => 'chat', 'provenance' => 'agent']]],
            ],
        ]);
    }

    public function testTheScriptReadsItsFieldsAndLabelsItsHarnessCalls(): void
    {
        $toolCallComparisonScript = ToolCallComparisonScript::fromJson(self::buildScriptJson(), 'Notes.json');

        self::assertSame('ComparisonNotesMCPPackage', $toolCallComparisonScript->packageName);
        self::assertSame(42, $toolCallComparisonScript->agentSpaceId);
        self::assertSame(['idempotencyKey'], $toolCallComparisonScript->opaqueTokenKeys);
        self::assertSame(hash('sha256', self::buildScriptJson()), $toolCallComparisonScript->contentHash);
        self::assertSame(['record'], $toolCallComparisonScript->getCaseIds());
        self::assertSame(['fixture#1', 'record#before1', 'record#before2'], $toolCallComparisonScript->getHarnessStepIds());
        $beforeSteps = array_values($toolCallComparisonScript->cases->first()->beforeSteps->getElements());
        self::assertSame(ToolCallComparisonToolCall::PROVENANCE_EVAL_HARNESS, $beforeSteps[0]->provenanceKind, 'a before call runs with eval-harness provenance unless it says otherwise');
        self::assertSame(ToolCallComparisonToolCall::PROVENANCE_AGENT, $beforeSteps[1]->provenanceKind);
        self::assertSame(ToolCallComparisonToolCall::PROVENANCE_EVAL_HARNESS, $toolCallComparisonScript->fixtureSteps->first()->provenanceKind);
        self::assertTrue($toolCallComparisonScript->conversationFixtures->findFixtureByName('plan')?->hasAgentWorkingState());
        self::assertSame(['Hello'], $toolCallComparisonScript->conversationFixtures->findFixtureByName('chat')?->ownerRows);
        self::assertSame([], $toolCallComparisonScript->findValidationErrors(['recordComparisonNote']));
    }

    public function testThePlaceholderOfTheAgentSpaceIsReplacedAtAnyDepth(): void
    {
        $case = ToolCallComparisonScript::fromJson(self::buildScriptJson(), 'Notes.json')->cases->first();

        self::assertSame(['agentSpaceId' => 77, 'nested' => ['agentSpaceId' => 77]], $case->buildArgumentsForAgentSpace(77));
        self::assertSame('{{agentSpaceId}}', $case->getArguments()['agentSpaceId'], 'the script keeps its placeholder');
    }

    public function testEveryScriptIsCheckedForItsCallsConversationsAndIds(): void
    {
        $toolCallComparisonScript = ToolCallComparisonScript::fromJson((string)json_encode([
            'conversations' => ['root' => [], 'child' => ['childOf' => 'root'], 'grandchild' => ['childOf' => 'child'], 'orphan' => ['childOf' => 'missing']],
            'cases' => [
                ['id' => 'a', 'tool' => 'recordComparisonNote', 'conversation' => 'root'],
                ['id' => 'a', 'tool' => 'unknownTool', 'conversation' => 'nowhere', 'provenance' => 'robot'],
                ['tool' => 'recordComparisonNote', 'conversation' => 'root'],
            ],
        ]), 'Broken.json');

        self::assertSame([
            'no "package"',
            'conversation grandchild: childOf must name a root conversation',
            'conversation orphan: childOf names no conversation (missing)',
            'a: the id is used twice',
            'a: tool "unknownTool" is no tool of the script\'s packages',
            'a: "conversation" must name a declared conversation (every call carries one)',
            'a: provenance "robot" is none of agent, evalHarness, none',
            'case #3: no "id"',
        ], $toolCallComparisonScript->findValidationErrors(['recordComparisonNote']));
        self::assertSame(['no "package"', 'no "cases"'], ToolCallComparisonScript::fromJson('{}', 'Empty.json')->findValidationErrors([]));
    }

    public function testTheApplicationsKeysAndRulesComeRightAfterThePackageCheck(): void
    {
        $hostScript = HostRulesToolCallComparisonScript::fromJson((string)json_encode([
            'spaceTemplate' => 'notes',
            'conversations' => ['chat' => []],
            'cases' => [['id' => 'fixed', 'tool' => 'recordComparisonNote', 'arguments' => ['recordId' => 9], 'conversation' => 'chat']],
        ]), 'Host.json');

        self::assertInstanceOf(HostRulesToolCallComparisonScript::class, $hostScript);
        self::assertSame('notes', $hostScript->spaceTemplate);
        self::assertSame(
            ['no "package"', 'the run would run as account 7', 'fixed: a fixed recordId'],
            $hostScript->findValidationErrors(['recordComparisonNote'], 7)
        );
        self::assertSame(['no "package"', 'fixed: a fixed recordId'], $hostScript->findValidationErrors(['recordComparisonNote'], HostRulesToolCallComparisonScript::COMPARISON_ACCOUNT_ID));
    }

    public function testATextThatIsNoObjectIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Notes.json is no JSON object');
        ToolCallComparisonScript::fromJson('[1', 'Notes.json');
    }
}
