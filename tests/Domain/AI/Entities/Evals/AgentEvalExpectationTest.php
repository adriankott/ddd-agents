<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentEvalResult;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\DelegationResults;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalExpectation;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The checks of an `expect` block against a driven conversation: every failure sentence byte for byte, in the order of
 * the key groups, and the call the case is checked on. The conversations are built in memory: a leading `load_skills`
 * call carries the loaded skills, then the tool calls, then the rows a case adds, then the final assistant text.
 */
class AgentEvalExpectationTest extends UnitTestCase
{
    /**
     * The verdict of the block on the conversation.
     *
     * @param array<string, mixed> $expect
     * @param array<string, int|string> $toolArgumentsFilledByThePlatform
     */
    protected function evaluate(AIConversation $conversation, array $expect, array $toolArgumentsFilledByThePlatform = []): AgentEvalResult
    {
        return AgentEvalExpectation::fromExpectBlock($expect)->buildEvalResult($conversation, $toolArgumentsFilledByThePlatform);
    }

    /**
     * A driven conversation: an optional leading `load_skills` call carrying `$loadedSkillNames`, then each
     * [name, arguments, status] tool call, then `$rowsBeforeTheAnswer`, then the final assistant text.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $toolCalls
     * @param string[] $loadedSkillNames
     * @param AIConversationMessage[] $rowsBeforeTheAnswer
     */
    protected function conversation(
        array $toolCalls,
        array $loadedSkillNames = ['Example.Figures'],
        string $finalAnswer = 'final answer',
        array $rowsBeforeTheAnswer = []
    ): AIConversation {
        $rows = [];
        if ($loadedSkillNames !== []) {
            $loadedCapabilityEntries = [];
            foreach ($loadedSkillNames as $loadedSkillName) {
                $loadedCapabilityEntries[$loadedSkillName] = ['name' => $loadedSkillName, 'type' => LoadedCapability::TYPE_SKILL];
            }
            $loadRow = $this->toolCallRow(LoadSkillsTool::NAME, '{}', AIConversationMessage::STATUS_COMPLETED);
            $loadRow->loadedCapabilities = LoadedCapabilities::fromCanonicalEntries($loadedCapabilityEntries);
            $rows[] = $loadRow;
        }
        foreach ($toolCalls as [$toolName, $toolArguments, $status]) {
            $rows[] = $this->toolCallRow($toolName, $toolArguments, $status);
        }
        foreach ($rowsBeforeTheAnswer as $rowBeforeTheAnswer) {
            $rows[] = $rowBeforeTheAnswer;
        }
        $rows[] = $this->assistantTextRow($finalAnswer);
        return $this->conversationOfRows($rows);
    }

    /** @param AIConversationMessage[] $rows */
    protected function conversationOfRows(array $rows): AIConversation
    {
        $messages = new AIConversationMessages();
        foreach ($rows as $indexInConversation => $row) {
            $row->indexInConversation = $indexInConversation;
            $messages->add($row);
        }
        $conversation = AIConversation::newInstance();
        $conversation->messages = $messages;
        return $conversation;
    }

    protected function toolCallRow(string $toolName, string $toolArguments, string $status): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->message = '';
        /** @var ToolCall $toolCall */
        $toolCall = self::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = $toolName;
        $toolCall->arguments = json_decode($toolArguments, true) ?: [];
        $toolCall->status = $status;
        $message->toolCall = $toolCall;
        return $message;
    }

    protected function assistantTextRow(string $text): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->message = $text;
        return $message;
    }

    protected function toolMessageRow(string $text): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_MESSAGE;
        $message->authorType = AIConversationMessage::AUTHOR_TOOL;
        $message->message = $text;
        return $message;
    }

    protected function signalRow(string $signalSource): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->messageType = AIConversationMessage::MESSAGE_SIGNAL;
        $message->signalSource = $signalSource;
        $message->message = '';
        return $message;
    }

    /** @param string[] $blockedToolNames */
    protected function evalRunSettingsBlocking(array $blockedToolNames): RunSettings
    {
        $runSettings = RunSettings::newInstance();
        $runSettings->evalRun = true;
        $runSettings->evalBlockedToolNames = $blockedToolNames;
        return $runSettings;
    }

    /** The conversation's only call of the tool. */
    protected function callOf(AIConversation $conversation, string $toolName): ToolCall
    {
        foreach ($conversation->messages->buildToolCallHistory()->getToolCallRows() as $toolCallRow) {
            if ($toolCallRow->toolCall->name === $toolName) {
                return $toolCallRow->toolCall;
            }
        }
        self::fail('no call of ' . $toolName);
    }

    public function testTheBlockIsKeptWholeAndItsToolAndSkillKeysAreRead(): void
    {
        $expect = [
            'skills' => ['Example.Figures', 'Example.Documents'],
            'skill' => 'Example.Figures',
            'tool' => ['readFigures', 'compareFigures'],
            'requiredTools' => 'listFigureSources',
            'performance' => ['maxModelCalls' => 4],
        ];
        $agentEvalExpectation = AgentEvalExpectation::fromExpectBlock($expect);

        self::assertSame($expect, $agentEvalExpectation->toArray(), 'an unknown key is kept for the code that reads it');
        self::assertSame(['Example.Figures', 'Example.Documents'], $agentEvalExpectation->getExpectedSkillNames());
        self::assertSame(['readFigures', 'compareFigures'], $agentEvalExpectation->getAnyOfToolNames());
        self::assertSame(['listFigureSources'], $agentEvalExpectation->getRequiredToolNames());

        $empty = AgentEvalExpectation::fromExpectBlock(['tool' => 'readFigures', 'skill' => ['not', 'a', 'name']]);
        self::assertSame([], $empty->getExpectedSkillNames(), 'a `skill` that is no string names no skill');
        self::assertSame(['readFigures'], $empty->getAnyOfToolNames());
        self::assertSame([], $empty->getRequiredToolNames());
    }

    public function testToolMessagesFinalAnswerPhrasesSignalSourcesAndCallCounts(): void
    {
        // a blocked rebuild followed by a false "rebuilt" claim: every check catches its part
        $blockedConversation = $this->conversation(
            [
                ['startBatchEdit', '{"pageWorkItems":[]}', AIConversationMessage::STATUS_COMPLETED],
                ['rebuildDocument', '{}', AIConversationMessage::STATUS_COMPLETED],
            ],
            ['Example.Documents'],
            'The preview was rebuilt successfully.',
            [
                $this->toolMessageRow("```widget\n{\"type\":\"BuildBlocked\",\"documentId\":238}\n```"),
                $this->signalRow(AIConversationMessage::SIGNAL_SOURCE_TURN_OBLIGATION_BOUNCE),
            ]
        );
        $this->callOf($blockedConversation, 'rebuildDocument')->result = json_encode(['content' => [], 'structuredContent' => ['built' => false, 'outcome' => 'BLOCKED', 'autoAssignedImageCount' => 0]]);

        $result = $this->evaluate($blockedConversation, [
            'skill' => 'Example.Documents',
            'tool' => 'rebuildDocument',
            'resultNotContains' => '"autoAssignedImageCount":0',
            'emitsToolMessage' => 'BuildResult',
            'forbidsToolMessage' => 'BuildBlocked',
            'finalAnswerNotContains' => ['WAS REBUILT', 'recreated'],
            'signalSources' => ['forbids' => ['TURN_OBLIGATION_BOUNCE'], 'requires' => ['DELEGATIONS_SETTLED']],
            'toolCallCountMax' => ['rebuildDocument' => 0],
            'toolCallCountMin' => ['addPages' => 1],
        ]);
        self::assertFalse($result->passed);
        self::assertSame([
            "resultNotContains: '\"autoAssignedImageCount\":0' found in the result of 'rebuildDocument'",
            "tool message not emitted: expected a TOOL_MESSAGE widget of type 'BuildResult' somewhere in the tree",
            "tool message emitted but forbidden: a TOOL_MESSAGE widget of type 'BuildBlocked' is in the tree",
            "finalAnswerNotContains: the final answer says 'WAS REBUILT'",
            "signalSources: expected a 'DELEGATIONS_SETTLED' signal in the tree, found none",
            "signalSources: a 'TURN_OBLIGATION_BOUNCE' signal fired although the case forbids it",
            "toolCallCountMax: 'rebuildDocument' was called 1 times, at most 0 allowed",
            "toolCallCountMin: 'addPages' was called 0 times, at least 1 expected",
        ], $result->failures);

        // the honest shape passes the same checks: built result, result widget, no bounce, no false claim
        $honestConversation = $this->conversation(
            [['rebuildDocument', '{}', AIConversationMessage::STATUS_COMPLETED]],
            ['Example.Documents'],
            'The preview shows the new version now.',
            [$this->toolMessageRow("```widget\n{\"type\":\"BuildResult\",\"builtRevision\":140}\n```")]
        );
        $this->callOf($honestConversation, 'rebuildDocument')->result = json_encode(['structuredContent' => ['built' => true, 'outcome' => 'BUILT', 'autoAssignedImageCount' => 3]]);
        $passing = $this->evaluate($honestConversation, [
            'skill' => 'Example.Documents',
            'tool' => 'rebuildDocument',
            'resultContains' => '"outcome":"BUILT"',
            'resultNotContains' => '"autoAssignedImageCount":0',
            'emitsToolMessage' => 'BuildResult',
            'forbidsToolMessage' => 'BuildBlocked',
            'finalAnswerNotContains' => ['was rebuilt'],
            'signalSources' => ['forbids' => ['TURN_OBLIGATION_BOUNCE']],
            'toolCallCountMax' => ['rebuildDocument' => 2],
        ]);
        self::assertTrue($passing->passed, implode("\n", $passing->failures));
    }

    public function testPassesWhenSkillToolStatusAndArgsAllMatch(): void
    {
        $conversation = $this->conversation([
            ['readFigures', '{"agentSpaceId":7001,"filters":"term eq \'alpha\' and dateFrom eq \'2026-05-26\' and dateTo eq \'2026-06-02\'"}', 'COMPLETED'],
        ]);

        $result = $this->evaluate($conversation, [
            'skill' => 'Example.Figures',
            'tool' => 'readFigures',
            'status' => 'COMPLETED',
            'argsContain' => ['filters' => ['dateFrom', 'dateTo', 'term']],
        ]);

        self::assertTrue($result->passed, implode(' | ', $result->failures));
        self::assertSame('readFigures', $result->matchedTool);
        self::assertSame([], $result->failures);
        self::assertSame($conversation, $result->conversation);
    }

    public function testFailsWhenExpectedSkillNotLoaded(): void
    {
        $conversation = $this->conversation([['readFigures', '{}', 'COMPLETED']], loadedSkillNames: []);
        $result = $this->evaluate($conversation, ['skill' => 'Example.Figures', 'tool' => 'readFigures']);

        self::assertFalse($result->passed);
        self::assertSame(["skill not loaded: expected 'Example.Figures', loaded []"], $result->failures);
    }

    public function testFailsWhenExpectedToolNotCalled(): void
    {
        $conversation = $this->conversation([['listFigureSources', '{}', 'COMPLETED']]);
        $result = $this->evaluate($conversation, ['skill' => 'Example.Figures', 'tool' => 'readFigures']);

        self::assertFalse($result->passed);
        self::assertNull($result->matchedTool);
        self::assertSame(['tool not called: expected one of [readFigures], called [' . LoadSkillsTool::NAME . ', listFigureSources]'], $result->failures);
    }

    public function testResultContainsAssertsTheMatchedCallsOutcome(): void
    {
        $blocked = $this->conversation([['rebuildDocument', '{}', 'COMPLETED']], ['Example.Documents']);
        $this->callOf($blocked, 'rebuildDocument')->result = '{"structuredContent":{"built":false,"violations":["x"]}}';
        $result = $this->evaluate($blocked, ['skill' => 'Example.Documents', 'tool' => 'rebuildDocument', 'resultContains' => '"built":true']);
        self::assertFalse($result->passed);
        self::assertCount(1, $result->failures);
        self::assertStringStartsWith("resultContains: '\"built\":true' not in the result of 'rebuildDocument' (", $result->failures[0]);

        $built = $this->conversation([['rebuildDocument', '{}', 'COMPLETED']], ['Example.Documents']);
        $this->callOf($built, 'rebuildDocument')->result = '{"structuredContent":{"built":true,"violations":[]}}';
        $passing = $this->evaluate($built, ['skill' => 'Example.Documents', 'tool' => 'rebuildDocument', 'resultContains' => ['"built":true', '"violations":[]']]);
        self::assertTrue($passing->passed, implode(' | ', $passing->failures));
    }

    public function testRequiredToolsDemandACompletedCallForEveryStageOfAFlow(): void
    {
        $conversation = $this->conversation([
            ['analyzeChange', '{}', 'COMPLETED'],
            ['addPages', '{}', 'FAILED'],
            ['rebuildDocument', '{}', 'COMPLETED'],
        ], ['Example.Documents']);
        $result = $this->evaluate($conversation, [
            'skill' => 'Example.Documents',
            'tool' => 'rebuildDocument',
            'requiredTools' => ['analyzeChange', 'addPages', 'startBatchEdit'],
        ]);

        self::assertFalse($result->passed);
        self::assertSame([
            "required tool not completed: 'addPages' must have a COMPLETED call, completed were [" . LoadSkillsTool::NAME . ', analyzeChange, rebuildDocument]',
            "required tool not completed: 'startBatchEdit' must have a COMPLETED call, completed were [" . LoadSkillsTool::NAME . ', analyzeChange, rebuildDocument]',
        ], $result->failures);

        $complete = $this->conversation([
            ['analyzeChange', '{}', 'COMPLETED'],
            ['addPages', '{}', 'COMPLETED'],
            ['startBatchEdit', '{}', 'COMPLETED'],
            ['rebuildDocument', '{}', 'COMPLETED'],
        ], ['Example.Documents']);
        $passing = $this->evaluate($complete, [
            'skill' => 'Example.Documents',
            'tool' => 'rebuildDocument',
            'requiredTools' => 'startBatchEdit',
        ]);
        self::assertTrue($passing->passed, implode(' | ', $passing->failures));
    }

    public function testForbiddenToolsFailForEveryCalledOne(): void
    {
        $conversation = $this->conversation([['readFigures', '{}', 'COMPLETED'], ['updateSettings', '{}', 'FAILED']]);
        $result = $this->evaluate($conversation, ['forbiddenTools' => ['updateSettings', 'deleteFigures']]);

        self::assertSame([
            "forbidden tool called: 'updateSettings' must NOT be called, but it was (called [" . LoadSkillsTool::NAME . ', readFigures, updateSettings])',
        ], $result->failures);
        self::assertNull($result->matchedTool, 'a guardrail case works without an expected tool');
        self::assertTrue($this->evaluate($conversation, ['forbiddenTools' => 'deleteFigures'])->passed);
    }

    public function testAnyOfToolListMatchesEitherTool(): void
    {
        $conversation = $this->conversation([['readFigures', '{}', 'COMPLETED']]);
        $result = $this->evaluate($conversation, [
            'skill' => 'Example.Figures',
            'tool' => ['compareFigures', 'readFigures'],
        ]);

        self::assertTrue($result->passed, implode(' | ', $result->failures));
        self::assertSame('readFigures', $result->matchedTool);
    }

    public function testFailsOnStatusMismatch(): void
    {
        $conversation = $this->conversation([['readFigures', '{}', 'FAILED']]);
        $result = $this->evaluate($conversation, ['skill' => 'Example.Figures', 'tool' => 'readFigures']);

        self::assertFalse($result->passed);
        self::assertSame(["tool 'readFigures' status: expected 'COMPLETED', got 'FAILED'"], $result->failures);
    }

    public function testAnyOfChecksTheCompletedCallThatCarriesTheAskedArguments(): void
    {
        // the draft is created first, the planned time is stored with a second call of an expected tool
        $conversation = $this->conversation([
            ['createDraft', '{"content":"probe"}', 'COMPLETED'],
            ['updateDraft', '{"draftId":7,"scheduleAt":"2026-11-13 14:30:00"}', 'COMPLETED'],
        ]);
        $result = $this->evaluate($conversation, [
            'skill' => 'Example.Figures',
            'tool' => ['createDraft', 'updateDraft'],
            'argsContain' => ['scheduleAt' => [' 14:30']],
        ]);

        self::assertTrue($result->passed, implode(' | ', $result->failures));
        self::assertSame('updateDraft', $result->matchedTool);
    }

    public function testAnyOfReportsTheMissingArgumentsOnTheFirstCompletedCallWhenNoCallCarriesThem(): void
    {
        $conversation = $this->conversation([
            ['createDraft', '{"content":"probe"}', 'COMPLETED'],
            ['updateDraft', '{"draftId":7}', 'COMPLETED'],
        ]);
        $result = $this->evaluate($conversation, [
            'skill' => 'Example.Figures',
            'tool' => ['createDraft', 'updateDraft'],
            'argsContain' => ['scheduleAt' => [' 14:30']],
        ]);

        self::assertFalse($result->passed);
        self::assertSame('createDraft', $result->matchedTool);
        self::assertSame(["argsContain[scheduleAt]: ' 14:30' not in args {\"content\":\"probe\"}"], $result->failures);
    }

    public function testANeedleWrittenForTheWholeArgumentsStillMatchesUnderItsOwnKey(): void
    {
        $conversation = $this->conversation([['readFigures', '{"dateFrom":"2026-01-01","term":"bikes"}', 'COMPLETED']]);

        $result = $this->evaluate($conversation, [
            'skill' => 'Example.Figures',
            'tool' => 'readFigures',
            'argsContain' => ['dateFrom' => ['"dateFrom":"2026-01', '2026-01-01'], 'term' => ['"term":"bikes"', 'bikes']],
        ]);
        self::assertSame([], $result->failures, 'the old whole-arguments form and the value form both match');

        $wrongValue = $this->evaluate($conversation, [
            'skill' => 'Example.Figures',
            'tool' => 'readFigures',
            'argsContain' => ['dateFrom' => ['"dateFrom":"2025']],
        ]);
        self::assertFalse($wrongValue->passed, 'the old form still fails on a wrong value');

        $otherArgument = $this->evaluate($conversation, [
            'skill' => 'Example.Figures',
            'tool' => 'readFigures',
            'argsContain' => ['dateFrom' => ['bikes']],
        ]);
        self::assertFalse($otherArgument->passed, 'a plain needle reads only the named argument');
    }

    public function testArgsContainReadsAnArgumentThePlatformFillsAsCarriedWhenTheCallLeavesItOut(): void
    {
        $expect = [
            'skill' => 'Example.Figures',
            'tool' => 'readCompleteness',
            'argsContain' => ['agentSpaceId' => ['412553']],
        ];
        foreach (['[]', '{}', '{"sections":["hours"]}'] as $argumentsWithoutTheAgentSpace) {
            $conversation = $this->conversation([['readCompleteness', $argumentsWithoutTheAgentSpace, 'COMPLETED']]);

            $withoutFilledArguments = $this->evaluate($conversation, $expect);
            self::assertFalse($withoutFilledArguments->passed, $argumentsWithoutTheAgentSpace);

            $withFilledArguments = $this->evaluate($conversation, $expect, ['agentSpaceId' => 412553]);
            self::assertTrue($withFilledArguments->passed, implode("\n", $withFilledArguments->failures));
        }
    }

    public function testArgsContainChecksAnArgumentTheCallPassedItselfAndNeverTheFilledValue(): void
    {
        $conversation = $this->conversation([['readCompleteness', '{"agentSpaceId":999}', 'COMPLETED']]);
        $result = $this->evaluate($conversation, [
            'skill' => 'Example.Figures',
            'tool' => 'readCompleteness',
            'argsContain' => ['agentSpaceId' => ['412553']],
        ], ['agentSpaceId' => 412553]);

        self::assertFalse($result->passed);
        self::assertSame(["argsContain[agentSpaceId]: '412553' not in args {\"agentSpaceId\":999}"], $result->failures);
    }

    public function testArgsAbsentReadsTheArgumentsAsTheModelSentThem(): void
    {
        $conversation = $this->conversation([['readCompleteness', '{}', 'COMPLETED']]);
        $result = $this->evaluate($conversation, [
            'skill' => 'Example.Figures',
            'tool' => 'readCompleteness',
            'argsAbsent' => ['agentSpaceId' => ['agentSpaceId']],
        ], ['agentSpaceId' => 412553]);

        self::assertTrue($result->passed, implode("\n", $result->failures));
    }

    public function testAnyOfPrefersTheCallThatCarriesTheAskedArgumentsOverACallWithFilledOnes(): void
    {
        $conversation = $this->conversation([
            ['createDraft', '{"content":"probe"}', 'COMPLETED'],
            ['updateDraft', '{"draftId":7,"scheduleAt":"2026-06-01 14:30"}', 'COMPLETED'],
        ]);
        $result = $this->evaluate($conversation, [
            'skill' => 'Example.Figures',
            'tool' => ['createDraft', 'updateDraft'],
            'argsContain' => ['scheduleAt' => [' 14:30'], 'agentSpaceId' => ['412553']],
        ], ['agentSpaceId' => 412553]);

        self::assertTrue($result->passed, implode("\n", $result->failures));
        self::assertSame('updateDraft', $result->matchedTool);
    }

    public function testACallTheEvaluationRunsToolBlockRefusedCountsAsMade(): void
    {
        $conversation = $this->conversation([['recreateDocument', '{}', 'FAILED']]);
        $conversation->runSettings = $this->evalRunSettingsBlocking(['recreateDocument']);

        $result = $this->evaluate($conversation, ['skill' => 'Example.Figures', 'tool' => 'recreateDocument']);

        self::assertTrue($result->passed, implode(' | ', $result->failures));
    }

    public function testAStatedStatusIsCheckedAlsoOnACallTheEvaluationRunRefused(): void
    {
        $conversation = $this->conversation([['recreateDocument', '{}', 'FAILED']]);
        $conversation->runSettings = $this->evalRunSettingsBlocking(['recreateDocument']);

        $result = $this->evaluate($conversation, ['skill' => 'Example.Figures', 'tool' => 'recreateDocument', 'status' => 'COMPLETED']);

        self::assertFalse($result->passed);
        self::assertSame(["tool 'recreateDocument' status: expected 'COMPLETED', got 'FAILED'"], $result->failures);
    }

    public function testAFailedCallOfAToolTheEvaluationRunDoesNotBlockStaysAFailure(): void
    {
        $conversation = $this->conversation([['readFigures', '{}', 'FAILED']]);
        $conversation->runSettings = $this->evalRunSettingsBlocking(['recreateDocument']);

        $result = $this->evaluate($conversation, ['skill' => 'Example.Figures', 'tool' => 'readFigures']);

        self::assertFalse($result->passed);
        self::assertSame(["tool 'readFigures' status: expected 'COMPLETED', got 'FAILED'"], $result->failures);
    }

    public function testFailsWhenArgsContainSubstringMissing(): void
    {
        $conversation = $this->conversation([['readFigures', '{"agentSpaceId":7001}', 'COMPLETED']]);
        $result = $this->evaluate($conversation, [
            'skill' => 'Example.Figures',
            'tool' => 'readFigures',
            'argsContain' => ['filters' => ['dateFrom']],
        ]);

        self::assertFalse($result->passed);
        self::assertSame(["argsContain[filters]: 'dateFrom' not in args {\"agentSpaceId\":7001}"], $result->failures);
    }

    public function testFailsWhenArgsAbsentSubstringPresent(): void
    {
        $conversation = $this->conversation([['readFigures', '{"filters":"rival eq \'x\'"}', 'COMPLETED']]);
        $result = $this->evaluate($conversation, [
            'skill' => 'Example.Figures',
            'tool' => 'readFigures',
            'argsAbsent' => ['filters' => ['rival']],
        ]);

        self::assertFalse($result->passed);
        self::assertSame(["argsAbsent[filters]: 'rival' unexpectedly in args"], $result->failures);
    }

    public function testEmitsWidgetPassesWhenFencedWidgetPresent(): void
    {
        $answer = "Here is your status.\n```widget\n{ \"type\": \"ProfileStatus\", \"agentSpaceId\": 412553 }\n```";
        $conversation = $this->conversation([['readSummary', '{}', 'COMPLETED']], ['Example.Profile'], $answer);
        $result = $this->evaluate($conversation, ['emitsWidget' => 'ProfileStatus']);

        self::assertTrue($result->passed, implode(' | ', $result->failures));
    }

    public function testEmitsWidgetFailsOnBareJsonWithoutFence(): void
    {
        // the widget JSON is present but not inside a widget fence: it renders nothing
        $answer = "Here is your status.\n{ \"type\": \"ProfileStatus\", \"agentSpaceId\": 412553 }";
        $conversation = $this->conversation([['readSummary', '{}', 'COMPLETED']], ['Example.Profile'], $answer);
        $result = $this->evaluate($conversation, ['emitsWidget' => 'ProfileStatus']);

        self::assertFalse($result->passed);
        self::assertSame(["widget not emitted: expected a ```widget block with type 'ProfileStatus' in the final answer"], $result->failures);
    }

    public function testEmitsWidgetReadsTheBlocksOwnTypeInAnySyntax(): void
    {
        // `~~~` and the `…Directive` suffix count; a "type" in a later fence that is no widget fence does not
        foreach (["~~~widget\n{\"type\":\"ProfileStatus\"}\n~~~", "```widget\n{\"type\":\"ProfileStatusDirective\"}\n```"] as $answer) {
            $conversation = $this->conversation([['readSummary', '{}', 'COMPLETED']], ['Example.Profile'], $answer);
            self::assertTrue($this->evaluate($conversation, ['emitsWidget' => 'ProfileStatus'])->passed, $answer);
        }
        $answer = "```widget\n{\"type\":\"EntriesList\"}\n```\n```json\n{\"type\":\"ProfileStatus\"}\n```";
        $conversation = $this->conversation([['readSummary', '{}', 'COMPLETED']], ['Example.Profile'], $answer);
        self::assertFalse($this->evaluate($conversation, ['emitsWidget' => 'ProfileStatus'])->passed);
        self::assertTrue($this->evaluate($conversation, ['forbidsWidget' => 'ProfileStatus'])->passed);

        // forbidsWidget=true: any widget block, valid or not, any syntax — a prose mention inside a line passes
        self::assertTrue($this->evaluate($this->conversation([], [], "Use a ```widget fence later."), ['forbidsWidget' => true])->passed);
        self::assertFalse($this->evaluate($this->conversation([], [], "Prose.\n```widget\n```"), ['forbidsWidget' => true])->passed);
        self::assertFalse($this->evaluate($this->conversation([], [], "```widget\n{\"type\":\"X\",}\n```"), ['forbidsWidget' => true])->passed);
        self::assertFalse($this->evaluate($this->conversation([], [], "~~~widget\n{\"type\":\"X\"}\n~~~"), ['forbidsWidget' => true])->passed);
        self::assertTrue($this->evaluate($this->conversation([], [], "~~~widget\n{\"type\":\"X\"}\n~~~"), ['forbidsWidget' => false])->passed, 'false checks nothing');
    }

    public function testEmitsAnyWidgetOfPassesWhenOneAcceptedTypeIsPresent(): void
    {
        $accepted = ['FiguresList', 'FiguresSnapshot', 'ComposedChart'];
        $answer = "Your figures.\n```widget\n{\"type\":\"FiguresSnapshot\",\"agentSpaceId\":412553}\n```";
        $conversation = $this->conversation([['readFigures', '{}', 'COMPLETED']], ['Example.Figures'], $answer);
        $result = $this->evaluate($conversation, ['emitsAnyWidgetOf' => $accepted]);
        self::assertTrue($result->passed, implode(' | ', $result->failures));

        // the all-of `emitsWidget` with the same list fails the same answer: that is why the any-of key exists
        self::assertFalse($this->evaluate($conversation, ['emitsWidget' => $accepted])->passed);

        // the `…Directive` form of an accepted type counts too
        $directiveAnswer = "Here.\n```widget\n{\"type\":\"FiguresListDirective\"}\n```";
        self::assertTrue($this->evaluate($this->conversation([], [], $directiveAnswer), ['emitsAnyWidgetOf' => $accepted])->passed);
    }

    public function testEmitsAnyWidgetOfFailsOnProseOtherTypesAndMalformedBlocks(): void
    {
        $accepted = ['EntriesSnapshot', 'ComposedChart'];
        $result = $this->evaluate($this->conversation([], [], 'Your entries went up.'), ['emitsAnyWidgetOf' => $accepted]);
        self::assertFalse($result->passed);
        self::assertSame(['widget not emitted: expected a widget block of one of the types [EntriesSnapshot, ComposedChart] in the final answer, found []'], $result->failures);

        $otherWidget = "Here.\n```widget\n{\"type\":\"EntriesList\"}\n```";
        $result = $this->evaluate($this->conversation([], [], $otherWidget), ['emitsAnyWidgetOf' => $accepted]);
        self::assertFalse($result->passed);
        self::assertSame(['widget not emitted: expected a widget block of one of the types [EntriesSnapshot, ComposedChart] in the final answer, found [EntriesList]'], $result->failures);

        // a malformed block of an accepted type is no emission
        $malformed = "Here.\n```widget\n{\"type\":\"ComposedChart\",}\n```";
        self::assertFalse($this->evaluate($this->conversation([], [], $malformed), ['emitsAnyWidgetOf' => $accepted])->passed);
    }

    public function testForbidsWidgetTruePassesOnPlainProse(): void
    {
        $conversation = $this->conversation([['listEntries', '{}', 'COMPLETED']], ['Example.Entries'], 'Most entries praise the quick replies.');
        $result = $this->evaluate($conversation, ['forbidsWidget' => true]);

        self::assertTrue($result->passed, implode(' | ', $result->failures));
    }

    public function testForbidsWidgetTrueFailsWhenAnyWidgetEmitted(): void
    {
        $answer = "Summary.\n```widget\n{ \"type\": \"EntriesList\", \"filters\": \"\" }\n```";
        $conversation = $this->conversation([['listEntries', '{}', 'COMPLETED']], ['Example.Entries'], $answer);
        $result = $this->evaluate($conversation, ['forbidsWidget' => true]);

        self::assertFalse($result->passed);
        self::assertSame(['widget emitted but none expected: the answer should stay prose (a summary / synthesis / narrow field lookup), yet it carries a widget block'], $result->failures);
    }

    public function testForbidsWidgetByNameFailsOnlyForThatWidget(): void
    {
        $answer = "Here.\n```widget\n{ \"type\": \"SingleEntry\", \"externalId\": \"x\" }\n```";
        $conversation = $this->conversation([['listEntries', '{}', 'COMPLETED']], ['Example.Entries'], $answer);

        // forbidding EntriesList passes (a SingleEntry is fine here) …
        self::assertTrue($this->evaluate($conversation, ['forbidsWidget' => 'EntriesList'])->passed);
        // … but forbidding SingleEntry fails
        $result = $this->evaluate($conversation, ['forbidsWidget' => 'SingleEntry']);
        self::assertFalse($result->passed);
        self::assertSame(["widget emitted but forbidden: the answer must not carry the 'SingleEntry' widget here"], $result->failures);
    }

    public function testEmitsProgressTextDemandsAnAssistantTextBeforeTheFirstToolCall(): void
    {
        $expect = ['emitsProgressText' => true];
        $silentFirstCall = $this->conversationOfRows([
            $this->toolCallRow('readFigures', '{}', 'COMPLETED'),
            $this->assistantTextRow('Here are your figures.'),
        ]);
        self::assertSame([
            'no progress preamble: expected a user-facing assistant text BEFORE the first tool call (the "I\'m fetching X now …" line that spares the user a silent spinner)',
        ], $this->evaluate($silentFirstCall, $expect)->failures);

        $preambleFirst = $this->conversationOfRows([
            $this->assistantTextRow('I am reading your figures now.'),
            $this->toolCallRow('readFigures', '{}', 'COMPLETED'),
            $this->assistantTextRow('Here are your figures.'),
        ]);
        self::assertTrue($this->evaluate($preambleFirst, $expect)->passed);

        $blankPreamble = $this->conversationOfRows([
            $this->assistantTextRow('   '),
            $this->toolCallRow('readFigures', '{}', 'COMPLETED'),
            $this->assistantTextRow('Here are your figures.'),
        ]);
        self::assertFalse($this->evaluate($blankPreamble, $expect)->passed, 'a blank text is no preamble');

        $noToolCall = $this->conversationOfRows([$this->assistantTextRow('Nothing to fetch.')]);
        self::assertTrue($this->evaluate($noToolCall, $expect)->passed, 'nothing to wait for');
        self::assertTrue($this->evaluate($silentFirstCall, ['emitsProgressText' => false])->passed, 'false checks nothing');
    }

    public function testSignalSourcesRequireAndForbidSignalsOfTheTree(): void
    {
        $conversation = $this->conversation([], [], 'done', [
            $this->signalRow(AIConversationMessage::SIGNAL_SOURCE_DELEGATIONS_SETTLED),
            $this->toolMessageRow('TURN_OBLIGATION_BOUNCE is only a text here'),
        ]);
        self::assertTrue($this->evaluate($conversation, ['signalSources' => [
            'requires' => [AIConversationMessage::SIGNAL_SOURCE_DELEGATIONS_SETTLED],
            'forbids' => [AIConversationMessage::SIGNAL_SOURCE_TURN_OBLIGATION_BOUNCE],
        ]])->passed);

        $result = $this->evaluate($conversation, ['signalSources' => [
            'forbids' => AIConversationMessage::SIGNAL_SOURCE_DELEGATIONS_SETTLED,
            'requires' => ['FIRST_MISSING', 'SECOND_MISSING'],
        ]]);
        self::assertSame([
            "signalSources: expected a 'FIRST_MISSING' signal in the tree, found none",
            "signalSources: expected a 'SECOND_MISSING' signal in the tree, found none",
            "signalSources: a 'DELEGATIONS_SETTLED' signal fired although the case forbids it",
        ], $result->failures);
        self::assertTrue($this->evaluate($conversation, ['signalSources' => 'not a map'])->passed, 'a block that is no map checks nothing');
    }

    public function testToolCallCountsBoundTheCallsOfEveryStatus(): void
    {
        $conversation = $this->conversation([
            ['rebuildDocument', '{}', 'FAILED'],
            ['rebuildDocument', '{}', 'COMPLETED'],
            ['rebuildDocument', '{}', 'COMPLETED'],
        ]);
        $result = $this->evaluate($conversation, [
            'toolCallCountMin' => ['rebuildDocument' => 4, 'addPages' => 1, 'readFigures' => 0],
            'toolCallCountMax' => ['rebuildDocument' => 2, 'addPages' => 0],
        ]);
        self::assertSame([
            "toolCallCountMax: 'rebuildDocument' was called 3 times, at most 2 allowed",
            "toolCallCountMin: 'rebuildDocument' was called 3 times, at least 4 expected",
            "toolCallCountMin: 'addPages' was called 0 times, at least 1 expected",
        ], $result->failures);
        self::assertTrue($this->evaluate($conversation, ['toolCallCountMax' => ['rebuildDocument' => 3], 'toolCallCountMin' => ['rebuildDocument' => 3]])->passed);
    }

    protected function planOf(TaskPlanItems $taskPlanItems): TaskPlan
    {
        $taskPlan = new TaskPlan();
        $taskPlan->items = $taskPlanItems;
        return $taskPlan;
    }

    protected function conversationWithPlan(?TaskPlan $taskPlan, AIConversationMessage ...$toolCallRows): AIConversation
    {
        $conversation = $this->conversationOfRows([...$toolCallRows, $this->assistantTextRow('done')]);
        if ($taskPlan !== null) {
            $conversation->getOrCreateAgentWorkingState()->taskPlan = $taskPlan;
        }
        return $conversation;
    }

    public function testTaskPlanCreatedIsTheDefaultAndCanBeTurnedOff(): void
    {
        $withoutPlan = $this->conversationWithPlan(null);
        self::assertSame(
            ['taskPlan: expected the agent to create a task plan (update_task_plan), but none exists'],
            $this->evaluate($withoutPlan, ['taskPlan' => []])->failures
        );
        self::assertTrue($this->evaluate($withoutPlan, ['taskPlan' => ['created' => false, 'minItems' => 3]])->passed, 'no plan, no item check');
        self::assertTrue($this->evaluate($withoutPlan, ['taskPlan' => true])->passed, 'a block that is no map checks nothing');
    }

    public function testTaskPlanSizeOpenItemsAndNesting(): void
    {
        $flatPlan = $this->planOf(TaskPlanItems::fromPurposeSeedDefinition(['Read the figures', 'Compare them', 'Write the summary']));
        $result = $this->evaluate($this->conversationWithPlan($flatPlan), ['taskPlan' => ['minItems' => 4, 'maxOpenItems' => 1, 'hasSubtasks' => true]]);
        self::assertSame([
            'taskPlan: expected ≥4 items, got 3',
            'taskPlan: expected ≤1 OPEN items at the end (finish or block every item), got 3 open',
            'taskPlan: expected a NESTED plan (subtasks), got a flat item list',
        ], $result->failures);

        $nestedItems = TaskPlanItems::fromPurposeSeedDefinition([
            ['text' => 'Prepare the document', 'subtasks' => [['text' => 'Collect the pages'], ['text' => 'Order the pages']]],
        ]);
        foreach ($nestedItems->getElements()[0]->subtasks->getElements() as $subtask) {
            $subtask->status = TaskPlanItem::STATUS_DONE;
            $subtask->statusNote = 'the pages are in place';
        }
        $passing = $this->evaluate($this->conversationWithPlan($this->planOf($nestedItems)), ['taskPlan' => ['minItems' => 3, 'maxOpenItems' => 0, 'hasSubtasks' => true, 'hasCompletionNotes' => true]]);
        self::assertTrue($passing->passed, implode("\n", $passing->failures));
    }

    public function testTaskPlanBlockedItemsAndCompletionNotes(): void
    {
        $taskPlanItems = TaskPlanItems::fromPurposeSeedDefinition(['Publish the page', 'Ask for the photo', 'Write the intro']);
        [$publishItem, $photoItem, $introItem] = $taskPlanItems->getElements();
        $publishItem->status = TaskPlanItem::STATUS_BLOCKED;
        $publishItem->statusNote = '';
        $photoItem->status = TaskPlanItem::STATUS_BLOCKED;
        $photoItem->statusNote = 'waiting for the owner';
        $introItem->status = TaskPlanItem::STATUS_DONE;
        $introItem->statusNote = null;
        $expect = ['taskPlan' => [
            'hasBlockedWithReason' => true,
            'hasRequiresUserAction' => true,
            'blockedRequiringUserActionItemTextContains' => 'PHOTO',
            'hasCompletionNotes' => true,
        ]];

        self::assertSame([
            'taskPlan: expected ≥1 BLOCKED item flagged requiresUserAction, found none',
            "taskPlan: expected the item naming 'PHOTO' BLOCKED with requiresUserAction, found none",
            "taskPlan: done item 'Write the intro' carries no statusNote (state what was produced)",
        ], $this->evaluate($this->conversationWithPlan($this->planOf($taskPlanItems)), $expect)->failures);

        $publishItem->requiresUserAction = true;
        self::assertSame(
            [
                "taskPlan: expected the item naming 'PHOTO' BLOCKED with requiresUserAction, found none",
                "taskPlan: done item 'Write the intro' carries no statusNote (state what was produced)",
            ],
            $this->evaluate($this->conversationWithPlan($this->planOf($taskPlanItems)), $expect)->failures,
            'another item blocked that way does not pass the named one'
        );

        $photoItem->requiresUserAction = true;
        $introItem->statusNote = 'the intro is written';
        $passing = $this->evaluate($this->conversationWithPlan($this->planOf($taskPlanItems)), $expect);
        self::assertTrue($passing->passed, implode("\n", $passing->failures));

        $photoItem->statusNote = ' ';
        self::assertSame(
            ['taskPlan: expected ≥1 BLOCKED item with a concrete blockReason, found none'],
            $this->evaluate($this->conversationWithPlan($this->planOf($taskPlanItems)), $expect)->failures
        );
    }

    public function testTaskPlanTickedViaSetTaskStatusNeedsACallThatChangedThePlan(): void
    {
        $taskPlan = $this->planOf(TaskPlanItems::fromPurposeSeedDefinition(['Read the figures']));
        $expect = ['taskPlan' => ['tickedViaSetTaskStatus' => true]];
        $noChangeCall = $this->toolCallRow(SetTaskStatusTool::NAME, '{}', AIConversationMessage::STATUS_COMPLETED);
        $noChangeCall->toolCall->result = SetTaskStatusTool::RESULT_PREFIX_NO_CHANGE_NEEDED . ' the item is already done.';
        $failedCall = $this->toolCallRow(SetTaskStatusTool::NAME, '{}', AIConversationMessage::STATUS_FAILED);

        self::assertSame(
            ['taskPlan: expected progress to be ticked via set_task_status (COMPLETED), found no such call'],
            $this->evaluate($this->conversationWithPlan($taskPlan, $noChangeCall, $failedCall), $expect)->failures
        );
        self::assertSame(
            [
                'taskPlan: expected the agent to create a task plan (update_task_plan), but none exists',
                'taskPlan: expected progress to be ticked via set_task_status (COMPLETED), found no such call',
            ],
            $this->evaluate($this->conversationWithPlan(null), $expect)->failures,
            'the tick check runs also without a plan'
        );

        $tickCall = $this->toolCallRow(SetTaskStatusTool::NAME, '{}', AIConversationMessage::STATUS_COMPLETED);
        $tickCall->toolCall->result = 'Updated 1 item.';
        self::assertTrue($this->evaluate($this->conversationWithPlan($taskPlan, $noChangeCall, $tickCall), $expect)->passed);
    }

    public function testTheFailuresFollowTheOrderOfTheKeyGroups(): void
    {
        $conversation = $this->conversation(
            [
                ['readFigures', '{"term":"alpha"}', 'FAILED'],
                ['forbiddenWrite', '{}', 'COMPLETED'],
            ],
            ['Example.Figures'],
            'The figures were sent.',
            [
                $this->toolMessageRow("```widget\n{\"type\":\"FiguresBlocked\"}\n```"),
                $this->signalRow(AIConversationMessage::SIGNAL_SOURCE_TURN_OBLIGATION_BOUNCE),
            ]
        );
        $this->callOf($conversation, 'readFigures')->result = 'no figures';
        // every key the block names, in an order different from the evaluation order
        $result = $this->evaluate($conversation, [
            'toolCallCountMin' => ['addPages' => 1],
            'signalSources' => ['forbids' => ['TURN_OBLIGATION_BOUNCE']],
            'finalAnswerNotContains' => 'were sent',
            'forbidsToolMessage' => 'FiguresBlocked',
            'resultNotContains' => 'no figures',
            'emitsProgressText' => true,
            'forbidsWidget' => true,
            'emitsAnyWidgetOf' => ['FiguresList'],
            'emitsWidget' => 'FiguresSnapshot',
            'taskPlan' => [],
            'forbiddenTools' => 'forbiddenWrite',
            'requiredTools' => 'compareFigures',
            'resultContains' => 'figures found',
            'argsAbsent' => ['term' => 'alpha'],
            'argsContain' => ['term' => 'beta'],
            'tool' => 'readFigures',
            'skill' => 'Example.Documents',
        ]);

        self::assertSame([
            "skill not loaded: expected 'Example.Documents', loaded [Example.Figures]",
            "tool 'readFigures' status: expected 'COMPLETED', got 'FAILED'",
            "argsContain[term]: 'beta' not in args {\"term\":\"alpha\"}",
            "argsAbsent[term]: 'alpha' unexpectedly in args",
            "resultContains: 'figures found' not in the result of 'readFigures' (no figures)",
            "required tool not completed: 'compareFigures' must have a COMPLETED call, completed were [" . LoadSkillsTool::NAME . ', forbiddenWrite]',
            "forbidden tool called: 'forbiddenWrite' must NOT be called, but it was (called [" . LoadSkillsTool::NAME . ', readFigures, forbiddenWrite])',
            'taskPlan: expected the agent to create a task plan (update_task_plan), but none exists',
            "widget not emitted: expected a ```widget block with type 'FiguresSnapshot' in the final answer",
            'widget not emitted: expected a widget block of one of the types [FiguresList] in the final answer, found []',
            'no progress preamble: expected a user-facing assistant text BEFORE the first tool call (the "I\'m fetching X now …" line that spares the user a silent spinner)',
            "resultNotContains: 'no figures' found in the result of 'readFigures'",
            "tool message emitted but forbidden: a TOOL_MESSAGE widget of type 'FiguresBlocked' is in the tree",
            "finalAnswerNotContains: the final answer says 'were sent'",
            "signalSources: a 'TURN_OBLIGATION_BOUNCE' signal fired although the case forbids it",
            "toolCallCountMin: 'addPages' was called 0 times, at least 1 expected",
        ], $result->failures);
        self::assertSame('readFigures', $result->matchedTool);
    }

    /**
     * A `delegate` call of the composed verdict map: its result is the map as the row composes it — a map of three
     * slices and a sync row stored as a list of two.
     */
    public function testResultTextOfALegacyDelegateCallIsItsVerdictMap(): void
    {
        $expect = [
            'tool' => 'delegate',
            'resultContains' => ['Sub-agent 1 — task:', 'Sub-agent 2 — task:'],
            'resultNotContains' => ['(sub-agent produced no verdict)', 'Sub-agent 3 — task:'],
        ];
        $threeSlices = [
            '971101' => ['task' => 'Research topic A.', 'verdict' => 'A has three parts.', 'costInUSD' => 0.0021],
            '971102' => ['task' => 'Research topic B.', 'verdict' => 'B has one part.', 'costInUSD' => 0.0018],
            '971103' => ['task' => 'Research topic C.', 'verdict' => 'C has two parts.', 'costInUSD' => 0.0024],
        ];
        $twoSlicesAsList = [
            ['task' => 'Fetch the current pictures.', 'verdict' => '4 pictures.', 'costInUSD' => 0.0031],
            ['task' => 'Find the current rating.', 'verdict' => '4.7 stars.', 'costInUSD' => 0.0012],
        ];

        $mapResult = $this->evaluate($this->conversationOfRows([$this->delegateCallRow(7001, '', $threeSlices)]), $expect);
        self::assertSame(
            ['passed' => false, 'failures' => ["resultNotContains: 'Sub-agent 3 — task:' found in the result of 'delegate'"], 'matchedTool' => 'delegate'],
            ['passed' => $mapResult->passed, 'failures' => $mapResult->failures, 'matchedTool' => $mapResult->matchedTool]
        );
        $listResult = $this->evaluate($this->conversationOfRows([$this->delegateCallRow(7002, '', $twoSlicesAsList)]), $expect);
        self::assertSame(
            ['passed' => true, 'failures' => [], 'matchedTool' => 'delegate'],
            ['passed' => $listResult->passed, 'failures' => $listResult->failures, 'matchedTool' => $listResult->matchedTool]
        );
    }

    /** @return array<string, mixed> a numbered sub-agent with one finished run */
    protected function subAgentRecordWithOneRun(): array
    {
        return [
            'number' => 3,
            'label' => 'Intro page',
            'task' => ['kind' => 'agent', 'taskText' => 'Write the intro page.', 'skillNames' => ['Example.Documents']],
            'childConversationId' => 812345,
            'requestedBy' => 'TOOL_RESULT',
            'requestedByToolName' => 'writePages',
            'currentRunNumber' => 1,
            'runs' => [
                '1' => [
                    'runNumber' => 1, 'status' => 'COMPLETED', 'queuedAt' => '2026-10-22 10:00:01', 'startedAt' => '2026-10-22 10:00:02',
                    'finishedAt' => '2026-10-22 10:02:00', 'costInUsd' => 0.0123, 'reportedToParentAt' => '2026-10-22 10:02:01',
                ],
            ],
        ];
    }

    public function testResultContainsOnADelegateCallReadsTheSubAgentsRunEnds(): void
    {
        $runEnd = $this->signalRow(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED);
        $runEnd->message = 'Sub-agent #3 «Intro page» COMPLETED (run 1, 2 min). Result:' . "\nThe page names 6 services.";
        $otherRunEnd = $this->signalRow(AIConversationMessage::SIGNAL_SOURCE_SUB_AGENT_RUN_ENDED);
        $otherRunEnd->message = 'Sub-agent #31 «Prices page» COMPLETED (run 1, 1 min). Result:' . "\nPrices from 40 EUR.";
        $conversation = $this->conversationOfRows([
            $this->delegateCallRow(7001, 'Sub-agent #3 «Intro page» started (running). Its result arrives as a notification when it ends.', ['subAgent' => $this->subAgentRecordWithOneRun()]),
            $runEnd,
            $otherRunEnd,
        ]);

        $passing = $this->evaluate($conversation, ['tool' => 'delegate', 'resultContains' => ['started (running)', '6 services']]);
        self::assertTrue($passing->passed, implode(' | ', $passing->failures));
        $otherSubAgents = $this->evaluate($conversation, ['tool' => 'delegate', 'resultContains' => 'Prices from 40 EUR']);
        self::assertFalse($otherSubAgents->passed, 'the notification of #31 is not the one of #3');
        $forbidden = $this->evaluate($conversation, ['tool' => 'delegate', 'resultNotContains' => '6 services']);
        self::assertSame(["resultNotContains: '6 services' found in the result of 'delegate'"], $forbidden->failures);

        $failedRecord = $this->subAgentRecordWithOneRun();
        $failedRecord['runs']['1']['status'] = 'TIMED_OUT';
        $failedRecord['runs']['1']['failureReason'] = 'timedOut';
        $failedRecord['runs']['1']['failureDetail'] = 'no step for 5 min';
        $failedConversation = $this->conversationOfRows([$this->delegateCallRow(7002, 'Sub-agent #3 «Intro page» started (running).', ['subAgent' => $failedRecord])]);
        $failedRun = $this->evaluate($failedConversation, ['tool' => 'delegate', 'resultContains' => 'run 1 TIMED_OUT: timedOut — no step for 5 min']);
        self::assertTrue($failedRun->passed, 'the record alone carries the run end: ' . implode(' | ', $failedRun->failures));

        $legacyConversation = $this->conversationOfRows([$this->delegateCallRow(7003, '', ['812' => ['task' => 'Read the entries.', 'verdict' => 'Average 4.6 stars.']])]);
        $legacy = $this->evaluate($legacyConversation, ['tool' => 'delegate', 'resultContains' => "Sub-agent 1 — task: Read the entries.\n→ Average 4.6 stars."]);
        self::assertTrue($legacy->passed, 'a legacy row reads its verdict map: ' . implode(' | ', $legacy->failures));
    }

    /** @param array<int|string, mixed> $storedDelegationResults */
    protected function delegateCallRow(int $messageId, string $spawnAnswer, array $storedDelegationResults): AIConversationMessage
    {
        $delegateCallRow = $this->toolCallRow('delegate', '{"task":"Write the intro page."}', AIConversationMessage::STATUS_COMPLETED);
        $delegateCallRow->id = $messageId;
        $delegateCallRow->toolCall->toolCallId = 'call_' . $messageId;
        $delegateCallRow->toolCall->result = $spawnAnswer;
        $delegationResults = DelegationResults::newInstance();
        $delegationResults->mapFromRepository($storedDelegationResults);
        $delegateCallRow->delegationResults = $delegationResults;
        return $delegateCallRow;
    }

    public function testArgsContainReadsTheValueOfItsArgument(): void
    {
        $conversation = $this->conversation([['searchFigures', '{"term":"winter","note":"summer sale","filters":{"region":"north"}}', AIConversationMessage::STATUS_COMPLETED]]);

        self::assertTrue($this->evaluate($conversation, ['tool' => 'searchFigures', 'argsContain' => ['term' => 'winter', 'filters.region' => 'north']])->passed);
        $result = $this->evaluate($conversation, ['tool' => 'searchFigures', 'argsContain' => ['term' => 'summer']]);
        self::assertSame(["argsContain[term]: 'summer' not in args {\"term\":\"winter\",\"note\":\"summer sale\",\"filters\":{\"region\":\"north\"}}"], $result->failures, 'a value of another argument does not count');
        self::assertFalse($this->evaluate($conversation, ['tool' => 'searchFigures', 'argsContain' => ['missing' => 'winter']])->passed, 'an argument the call does not carry');
        self::assertTrue($this->evaluate($conversation, ['tool' => 'searchFigures', 'argsContain' => ['summer sale']])->passed, 'a list without names reads the whole arguments');
        self::assertTrue($this->evaluate($conversation, ['tool' => 'searchFigures', 'argsContain' => ['filters' => '"region":"north"']])->passed, 'an object value is read as its unescaped JSON');
    }

    public function testResultChecksReadTheUnescapedResultAsTheArgumentChecksDo(): void
    {
        $conversation = $this->conversation([['readFigures', '{"path":"/reports/äpfel"}', AIConversationMessage::STATUS_COMPLETED]]);
        $this->callOf($conversation, 'readFigures')->result = json_encode(
            ['content' => [['type' => 'text', 'text' => 'Report "Äpfel" at /reports/äpfel']], 'structuredContent' => ['count' => 3, 'path' => '/reports/äpfel']]
        );

        self::assertTrue($this->evaluate($conversation, ['tool' => 'readFigures', 'argsContain' => ['path' => '/reports/äpfel'], 'resultContains' => ['Report "Äpfel"', '"path":"/reports/äpfel"']])->passed);
        self::assertSame(
            ["resultNotContains: '/reports/äpfel' found in the result of 'readFigures'"],
            $this->evaluate($conversation, ['tool' => 'readFigures', 'resultNotContains' => '/reports/äpfel'])->failures
        );
        self::assertFalse($this->evaluate($conversation, ['tool' => 'readFigures', 'resultContains' => '\\u00e4'])->passed, 'the JSON escapes are not compared');
    }

    public function testForbiddenSkillsAndNoSkill(): void
    {
        $conversationWithSkill = $this->conversation([], ['Example.Figures']);
        $conversationWithoutSkill = $this->conversation([], []);

        self::assertSame(["forbidden skill loaded: 'Example.Figures'"], $this->evaluate($conversationWithSkill, ['forbiddenSkills' => ['Example.Figures', 'Example.Documents']])->failures);
        self::assertTrue($this->evaluate($conversationWithSkill, ['forbiddenSkills' => 'Example.Documents'])->passed);
        self::assertSame(['skill loaded although none was expected: [Example.Figures]'], $this->evaluate($conversationWithSkill, ['noSkill' => true])->failures);
        self::assertTrue($this->evaluate($conversationWithoutSkill, ['noSkill' => true])->passed);
    }

    public function testAJudgedRunIsGatedOnWhatHappenedInTheTreeNotOnTheShapeOfItsLastTurn(): void
    {
        $conversation = $this->conversation([['getFigures', '{}', AIConversationMessage::STATUS_FAILED]], ['Example.Figures'], 'plain prose, no widget');
        $expectation = AgentEvalExpectation::fromExpectBlock([
            'skill' => 'Example.Figures',
            'requiredTools' => ['getFigures'],
            'forbiddenTools' => ['deleteFigures'],
            'emitsWidget' => 'figureTable',
            'toolCallCountMax' => ['getFigures' => 0],
        ]);
        $judgedFailures = $expectation->evaluateConversationForJudgedRun($conversation);
        self::assertSame(["required tool not completed: 'getFigures' must have a COMPLETED call, completed were [load_skills]"], $judgedFailures);
        self::assertGreaterThan(count($judgedFailures), count($expectation->evaluateConversation($conversation)), 'the single shot also checks the last turn\'s widget and the call count');
        self::assertSame([], AgentEvalExpectation::fromExpectBlock(['emitsWidget' => 'figureTable'])->evaluateConversationForJudgedRun($conversation));
    }

}
