<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNode;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionPreservedFacts;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalCompactionTreeRunResult;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgedRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimRunResult;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\ConversationCompactionNodesService;
use DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * What the driver reads (the owner's and the assistant's texts, the widget rows, the owner's widget actions, a widget
 * of the application as the owner reads it — every block in place, its line ending kept — and the fresh rows of a
 * stored conversation), how the prompts are filled (the driver's goal and facts, the verdict prompt's application
 * parts and the trace set last), the throwaway conversation of a harness model call, and the compaction-tree run.
 */
class AgentEvalJudgedRunServiceTranscriptAndPromptsTest extends IntegrationTestCase
{
    protected AgentEvalRunHooksServiceRecordingDouble $hooksDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hooksDouble = new AgentEvalRunHooksServiceRecordingDouble();
        $this->replaceService(AgentEvalRunHooksService::class, $this->hooksDouble);
    }

    protected function buildMessage(string $messageType, string $role, string $authorType, string $text, ?string $signalSource = null): AIConversationMessage
    {
        $message = AIConversationMessage::newInstance();
        $message->messageType = $messageType;
        $message->role = $role;
        $message->authorType = $authorType;
        $message->message = $text;
        $message->signalSource = $signalSource;
        return $message;
    }

    protected function buildConversation(AIConversationMessage ...$orderedMessages): AIConversation
    {
        /** @var AIConversation $conversation */
        $conversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        $conversation->messages = new AIConversationMessages();
        foreach ($orderedMessages as $message) {
            $conversation->messages->add($message);
        }
        return $conversation;
    }

    protected function buildScope(): AgentEvalScope
    {
        $ownerAccount = new Account();
        $ownerAccount->id = 5;
        return AgentEvalScope::forAgentSpace(null, $ownerAccount);
    }

    protected function buildSimCase(): AgentEvalSimCase
    {
        return AgentEvalSimCase::fromArray([
            'goal' => 'the goal',
            'openingPrompt' => 'the opening',
            'userFacts' => 'Active space. Change nothing.',
            'successRubric' => 'the rubric',
        ]);
    }

    public function testTheTranscriptShowsWhatTheOwnerSeesWithItsLabelsAndNothingElse(): void
    {
        $conversation = $this->buildConversation(
            $this->buildMessage(AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, ' Show me my entries. '),
            $this->buildMessage(AIConversationMessage::MESSAGE_TOOL_CALL, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_AGENT, 'tool call text'),
            $this->buildMessage(AIConversationMessage::MESSAGE_TOOL_MESSAGE, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_TOOL, 'Is this you?'),
            $this->buildMessage(AIConversationMessage::MESSAGE_SIGNAL, AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, 'The owner confirmed the pin.', AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK),
            $this->buildMessage(AIConversationMessage::MESSAGE_SIGNAL, AIConversationMessage::ROLE_SYSTEM, AIConversationMessage::AUTHOR_AGENT, 'You have the skills loaded', AIConversationMessage::SIGNAL_SOURCE_LOOP_NUDGE),
            $this->buildMessage(AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'Here they are.'),
            $this->buildMessage(AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, '   '),
        );

        self::assertSame(
            "OWNER: Show me my entries.\n\nASSISTANT: Is this you?\n\n[Owner action in the widget: The owner confirmed the pin.]\n\nASSISTANT: Here they are.",
            (new AgentEvalJudgedRunServiceScriptedModelsDouble())->getUserVisibleTranscript($conversation)
        );
    }

    public function testEachWidgetOfTheApplicationIsReplacedInPlaceWithItsLineEndingAndEveryOtherBlockStays(): void
    {
        $firstBlock = "```widget\n{\"type\":\"IdentityConfirm\",\"matches\":[{\"id\":\"a\"}]}\n```\n";
        $otherBlock = "```widget\n{\"type\":\"QuickReplies\",\"options\":[\"Yes\"]}\n```\n";
        $secondBlock = "```widget\n{\"type\":\"IdentityConfirm\",\"matches\":[{\"id\":\"b\"}]}\n```";
        $assistantText = "Is one of these yours?\n" . $firstBlock . "Or this:\n" . $otherBlock . $secondBlock;
        $agentEvalJudgedRunService = new AgentEvalJudgedRunServiceScriptedModelsDouble();
        $agentEvalJudgedRunService->hostWidgetType = 'IdentityConfirm';

        $transcript = $agentEvalJudgedRunService->getUserVisibleTranscript($this->buildConversation(
            $this->buildMessage(AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, $assistantText)
        ));

        self::assertSame(
            "ASSISTANT: Is one of these yours?\n[widget 0 as the owner sees it]\nOr this:\n" . $otherBlock . '[widget 2 as the owner sees it]',
            $transcript
        );
        $agentEvalJudgedRunService->hostWidgetType = null;
        self::assertSame('ASSISTANT: ' . $assistantText, $agentEvalJudgedRunService->getUserVisibleTranscript($this->buildConversation(
            $this->buildMessage(AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, $assistantText)
        )), 'by default every block stays as written');
    }

    public function testAStoredConversationShowsItsFreshRowsAndFallsBackToTheSetInMemory(): void
    {
        $messagesServiceDouble = new AIConversationMessagesServiceWithStoredRowsDouble();
        $this->replaceService(AIConversationMessagesService::class, $messagesServiceDouble);
        $conversation = $this->buildConversation($this->buildMessage(AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'in memory'));
        $conversation->id = 4711;
        $agentEvalJudgedRunService = new AgentEvalJudgedRunServiceScriptedModelsDouble();

        self::assertSame('OWNER: in memory', $agentEvalJudgedRunService->getUserVisibleTranscript($conversation), 'a read that returns nothing falls back');

        $messagesServiceDouble->storedMessages = $this->buildConversation(
            $this->buildMessage(AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'in memory'),
            $this->buildMessage(AIConversationMessage::MESSAGE_TOOL_MESSAGE, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_TOOL, 'a widget row a child process stored')
        )->messages;
        self::assertSame("OWNER: in memory\n\nASSISTANT: a widget row a child process stored", $agentEvalJudgedRunService->getUserVisibleTranscript($conversation));
        self::assertSame([true, true], $messagesServiceDouble->readsPastTheEntityRegistry);
    }

    public function testTheDriverPromptCarriesTheGoalTheFactsAndTheTranscript(): void
    {
        $conversation = $this->buildConversation($this->buildMessage(AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'the opening'));

        $driverPromptText = (new AgentEvalJudgedRunServiceScriptedModelsDouble())->buildDriverPrompt($conversation, $this->buildSimCase())->getPromptTextWithParametersApplied();

        self::assertStringContainsString('YOUR GOAL: the goal', $driverPromptText);
        self::assertStringContainsString('Active space. Change nothing.', $driverPromptText);
        self::assertStringContainsString("THE CHAT SO FAR (only what you see as the owner):\nOWNER: the opening", $driverPromptText);
        self::assertStringContainsString('you ask for nothing that goes beyond YOUR GOAL', (string)preg_replace('/\s+/', ' ', $driverPromptText));
        self::assertSame(0, preg_match('/\{%[A-Z_]+%\}/', $driverPromptText), 'no placeholder is left');
    }

    public function testTheVerdictPromptLeavesTheApplicationsPartsEmptyByDefaultAndSetsTheTraceLast(): void
    {
        $conversation = $this->buildConversation();
        $agentEvalJudgedRunService = new AgentEvalJudgedRunServiceScriptedModelsDouble();

        $verdictPromptText = $agentEvalJudgedRunService->buildVerdictPrompt($conversation, $this->buildSimCase(), 'the trace quoting {%RUBRIC%} and {%HOST_RULES%}')->getPromptTextWithParametersApplied();

        self::assertStringContainsString("OWNER'S GOAL: the goal\nOPENING MESSAGE: the opening\nSUCCESS RUBRIC: the rubric", $verdictPromptText);
        self::assertStringContainsString('the trace quoting {%RUBRIC%} and {%HOST_RULES%}', $verdictPromptText, 'the trace is set last and stays verbatim');
        self::assertStringContainsString("the trace quoting {%RUBRIC%} and {%HOST_RULES%}\n\nHOW TO READ THE TRACE", $verdictPromptText, 'no section of the application: an empty line');
        self::assertStringContainsString("that is the right answer to the request.\n\n- REQUIRED vs ADVISORY", $verdictPromptText, 'no rule of the application: an empty line');
        $hardListStart = (int)strpos($verdictPromptText, '- HARD (devalues the result)');
        $hardList = (string)preg_replace('/\s+/', ' ', substr($verdictPromptText, $hardListStart, (int)strpos($verdictPromptText, '- MINOR (path/UX') - $hardListStart));
        self::assertStringContainsString('a VALUE from the draft the owner approved that is MISSING from the END STATE', $hardList);
    }

    public function testTheApplicationsRulesCloseTheMinorListAndItsSectionsFollowTheTrace(): void
    {
        $agentEvalJudgedRunService = new AgentEvalJudgedRunServiceScriptedModelsDouble();
        $agentEvalJudgedRunService->hostRules = '- the application\'s rule';
        $agentEvalJudgedRunService->hostSections = 'THE APPLICATION\'S SECTION';

        $verdictPromptText = $agentEvalJudgedRunService->buildVerdictPrompt($this->buildConversation(), $this->buildSimCase(), 'THE TRACE')->getPromptTextWithParametersApplied();

        self::assertStringContainsString("THE TRACE\nTHE APPLICATION'S SECTION\n", $verdictPromptText);
        self::assertStringContainsString("\n- the application's rule\n- REQUIRED vs ADVISORY", $verdictPromptText);
    }

    public function testAHarnessPromptIsTheOneOwnerMessageOfAThrowawayConversationOfTheScope(): void
    {
        $agentEvalJudgedRunOptions = AgentEvalJudgedRunOptions::forArms();

        /** @var AIConversation $promptConversation */
        $promptConversation = (new ReflectionMethod(AgentEvalJudgedRunService::class, 'buildHarnessPromptConversation'))
            ->invoke(new AgentEvalJudgedRunService(), $this->buildScope(), 'Judge this.');

        self::assertSame(1, $promptConversation->messages->count());
        $promptMessage = $promptConversation->messages->last();
        self::assertSame('Judge this.', $promptMessage->message);
        self::assertSame(AIConversationMessage::ROLE_USER, $promptMessage->role);
        self::assertSame(5, $promptConversation->accountId);
        self::assertFalse(isset($promptConversation->id));
        self::assertSame(['attachNewConversationToScope'], $this->hooksDouble->hookCalls);
        self::assertNull($agentEvalJudgedRunOptions->takeBuiltConversation(), 'never recorded as the run\'s conversation');
    }

    public function testTheCompactionTreeRunDrivesReportsItsStepsAndAsksTheJudgeOnTheTree(): void
    {
        $agentEvalJudgedRunService = new AgentEvalJudgedRunServiceScriptedModelsDouble();
        $agentEvalJudgedRunService->scriptedDriverReplies = ['', 'Tell me about the opening hours.', '[GIVE-UP]'];
        $agentEvalJudgedRunService->scriptedJudgeAnswers = ['{"verdict":"FAIL","treeGrew":false,"reasons":"no roll-ups"}'];
        $progressLinesRecorder = new AgentEvalProgressLinesRecorder();
        $agentEvalJudgedRunOptions = AgentEvalJudgedRunOptions::forArms();
        $agentEvalJudgedRunOptions->progressReporter = $progressLinesRecorder;
        $agentEvalJudgedRunOptions->aiModelName = 'agent-model';
        $simCase = AgentEvalSimCase::fromArray([
            'goal' => 'a long chat',
            'openingPrompt' => 'Hello, remember the code 4711.',
            'compactionParamsText' => 'segmentMessages=4, fanIn=2',
            'compactionSettings' => ['triggerTokens' => 500],
            'maxTurns' => 5,
        ]);

        $agentEvalCompactionTreeRunResult = $agentEvalJudgedRunService->runCompactionTreeEval($this->buildScope(), $simCase, $agentEvalJudgedRunOptions);

        self::assertInstanceOf(AgentEvalCompactionTreeRunResult::class, $agentEvalCompactionTreeRunResult);
        self::assertSame(AgentEvalSimRunResult::STOP_REASON_GIVE_UP, $agentEvalCompactionTreeRunResult->getStopReason());
        self::assertSame(3, $agentEvalCompactionTreeRunResult->getTurns());
        self::assertSame(
            [['Hello, remember the code 4711.'], ['Hello, remember the code 4711.', 'Tell me more about that.'], ['Hello, remember the code 4711.', 'Tell me more about that.', 'Tell me about the opening hours.']],
            $agentEvalJudgedRunService->ownerTextsPerDrive
        );
        self::assertSame([1 => '(tree empty — nothing compacted yet)', 2 => '(tree empty — nothing compacted yet)', 3 => '(tree empty — nothing compacted yet)'], $agentEvalCompactionTreeRunResult->getTreeSnapshots());
        self::assertSame(500, $agentEvalCompactionTreeRunResult->getConversation()->compactionSettings->triggerTokens);
        self::assertSame('FAIL', $agentEvalCompactionTreeRunResult->getAgentEvalJudgeVerdict()->getVerdict());
        self::assertSame(
            [AgentAIUsageScope::AI_AGENT_SIMULATE_OWNER, AgentAIUsageScope::AI_AGENT_SIMULATE_OWNER, AgentAIUsageScope::AI_AGENT_SIMULATE_OWNER, AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION],
            $agentEvalJudgedRunService->getUsageScopesOfModelCalls()
        );
        $driverPromptText = $agentEvalJudgedRunService->modelCalls[0]['promptText'];
        self::assertStringContainsString('COMPACTION PARAMETERS (you steer by them): segmentMessages=4, fanIn=2', $driverPromptText);
        $verdictPromptText = $agentEvalJudgedRunService->modelCalls[3]['promptText'];
        self::assertStringContainsString("── after turn 1 ──\n(tree empty — nothing compacted yet)\n\n── after turn 2 ──", $verdictPromptText);
        self::assertStringContainsString("SUCCESS RUBRIC:\nThe tree is GOOD when all criteria below hold.", $verdictPromptText, 'the default rubric of a case without one');
        self::assertSame('eval start — driving up to 5 turns (agent=agent-model, driver=ANTHROPIC.CLAUDE_SONNET_5, judge=ANTHROPIC.CLAUDE_OPUS_5_5)', $progressLinesRecorder->progressLines[0]);
        self::assertSame('turn 1/5: driving agent…', $progressLinesRecorder->progressLines[1]);
        self::assertMatchesRegularExpression('/^turn 1: agent done \(\d+\.\ds\), tree=0 nodes — calling driver…$/', $progressLinesRecorder->progressLines[2]);
        self::assertMatchesRegularExpression('/^turn 1: driver done \(\d+\.\ds\) → ""$/', $progressLinesRecorder->progressLines[3]);
        self::assertSame('all turns done — calling judge…', $progressLinesRecorder->progressLines[count($progressLinesRecorder->progressLines) - 2]);
        self::assertMatchesRegularExpression('/^judge done \(\d+\.\ds\)$/', $progressLinesRecorder->progressLines[count($progressLinesRecorder->progressLines) - 1]);
    }

    protected function buildCompactionNode(int $level, int $coveredFrom, int $coveredTo, bool $fromRawMessages, string $summaryText, string ...$preservedIdentifiers): AIConversationCompactionNode
    {
        $compactionNode = AIConversationCompactionNode::newInstance();
        $compactionNode->level = $level;
        $compactionNode->coveredFromMessageIndex = $coveredFrom;
        $compactionNode->coveredToMessageIndex = $coveredTo;
        $compactionNode->coveredMessageCount = $coveredTo - $coveredFrom + 1;
        $compactionNode->fromRawMessages = $fromRawMessages;
        $compactionNode->summaryText = $summaryText;
        $preservedFacts = AIConversationCompactionPreservedFacts::newInstance();
        $preservedFacts->identifiers = $preservedIdentifiers;
        $compactionNode->preservedFacts = $preservedFacts;
        return $compactionNode;
    }

    protected function buildCompactionTree(): AIConversationCompactionNodes
    {
        $compactionNodes = AIConversationCompactionNodes::newInstance();
        // add() takes its element by reference
        $firstLeaf = $this->buildCompactionNode(0, 0, 3, true, "The owner asked about\nthe opening hours.");
        $secondLeaf = $this->buildCompactionNode(0, 4, 7, true, 'The owner asked about the entries of the last week and their order, newest first.');
        $rollUp = $this->buildCompactionNode(1, 0, 7, false, 'Opening hours and entries.', 'code 4711', 'Main Street 5');
        $compactionNodes->add($firstLeaf);
        $compactionNodes->add($secondLeaf);
        $compactionNodes->add($rollUp);
        return $compactionNodes;
    }

    public function testTheTreeSnapshotListsTheNodesHighestLevelFirstWithTheirPreservedFactsAndTheCover(): void
    {
        $conversation = $this->buildConversation();
        $conversation->compactionNodes = $this->buildCompactionTree();

        $treeSnapshot = (string)(new ReflectionMethod(AgentEvalJudgedRunService::class, 'renderTreeSnapshot'))->invoke(new AgentEvalJudgedRunService(), $conversation);

        self::assertSame(
            "L1 [0–7] 8msg rollup: Opening hours and entries. {preserved: code 4711 | Main Street 5}\n"
            . "L0 [0–3] 4msg leaf: The owner asked about the opening hours.\n"
            . "L0 [4–7] 4msg leaf: The owner asked about the entries of the last week and their order, n…\n"
            . "COVER (what the model receives): L1[0-7] · edge=msg7\n"
            . "\n"
            . "RENDERED COVER (the exact model input):\n"
            . "PRESERVED FACTS & IDENTIFIERS (stated earlier, carried verbatim):\n"
            . "code 4711\n"
            . "Main Street 5\n"
            . "\n"
            . "[messages 0–7]\n"
            . "Opening hours and entries.",
            $treeSnapshot
        );
    }

    public function testTheCompactionTreeRunStopsAtTheCasesLimitWithASnapshotPerTurn(): void
    {
        $agentEvalJudgedRunService = new AgentEvalJudgedRunServiceScriptedModelsDouble();
        $agentEvalJudgedRunService->scriptedDriverReplies = ['And the prices?', 'And the team?'];
        $agentEvalJudgedRunService->scriptedJudgeAnswers = ['{"verdict":"PASS","treeGrew":true,"reasons":"grew"}'];

        $agentEvalCompactionTreeRunResult = $agentEvalJudgedRunService->runCompactionTreeEval(
            $this->buildScope(),
            AgentEvalSimCase::fromArray(['goal' => 'a long chat', 'openingPrompt' => 'Hello.', 'maxTurns' => 2]),
            AgentEvalJudgedRunOptions::forArms()
        );

        self::assertSame(AgentEvalSimRunResult::STOP_REASON_MAX_TURNS, $agentEvalCompactionTreeRunResult->getStopReason());
        self::assertSame(2, $agentEvalCompactionTreeRunResult->getTurns());
        self::assertSame([1, 2], array_keys($agentEvalCompactionTreeRunResult->getTreeSnapshots()));
        self::assertSame([['Hello.'], ['Hello.', 'And the prices?']], $agentEvalJudgedRunService->ownerTextsPerDrive, 'the last driver answer is never sent');
        self::assertSame('PASS', $agentEvalCompactionTreeRunResult->getAgentEvalJudgeVerdict()->getVerdict());
    }

    public function testAStoredCompactionTreeRunIsJudgedOnTheStoredRowsAndTheWholeStoredTree(): void
    {
        $messagesServiceDouble = new AIConversationMessagesServiceWithStoredRowsDouble();
        $this->replaceService(AIConversationMessagesService::class, $messagesServiceDouble);
        $conversationsServiceDouble = new AIConversationsServiceWithStoredConversationDouble();
        $this->replaceService(AIConversationsService::class, $conversationsServiceDouble);
        $compactionNodesServiceDouble = new ConversationCompactionNodesServiceWithStoredNodesDouble();
        $compactionNodesServiceDouble->storedCompactionNodes = $this->buildCompactionTree();
        $this->replaceService(ConversationCompactionNodesService::class, $compactionNodesServiceDouble);
        $messagesServiceDouble->storedMessages = $this->buildConversation(
            $this->buildMessage(AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'Hello, remember the code 4711.'),
            $this->buildMessage(AIConversationMessage::MESSAGE_TEXT, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, 'an answer a worker stored')
        )->messages;
        $agentEvalJudgedRunService = new AgentEvalJudgedRunServiceScriptedModelsDouble();
        $agentEvalJudgedRunService->scriptedDriverReplies = ['[GIVE-UP]'];
        $agentEvalJudgedRunService->scriptedJudgeAnswers = ['{"verdict":"PASS","reasons":"grew"}'];
        $agentEvalJudgedRunOptions = AgentEvalJudgedRunOptions::forArms();
        $agentEvalJudgedRunOptions->persistConversations = true;

        $agentEvalCompactionTreeRunResult = $agentEvalJudgedRunService->runCompactionTreeEval(
            $this->buildScope(),
            AgentEvalSimCase::fromArray(['goal' => 'a long chat', 'openingPrompt' => 'Hello, remember the code 4711.', 'maxTurns' => 3]),
            $agentEvalJudgedRunOptions
        );

        self::assertSame(AIConversationsServiceWithStoredConversationDouble::STORED_CONVERSATION_ID, $conversationsServiceDouble->storedConversation?->id, 'stored before the first turn');
        self::assertSame(['Hello, remember the code 4711.'], $messagesServiceDouble->storedInboundMessageTexts);
        self::assertSame(1, $conversationsServiceDouble->reads, 'read back once, before the judge');
        self::assertSame([4711, 4711], $compactionNodesServiceDouble->readConversationIds, 'the whole stored tree for the turn\'s snapshot and for the final tree');
        self::assertStringStartsWith('L1 [0–7] 8msg rollup: Opening hours and entries. {preserved: code 4711 | Main Street 5}', $agentEvalCompactionTreeRunResult->getTreeSnapshots()[1]);
        self::assertStringStartsWith('L1 [0–7] 8msg rollup:', $agentEvalCompactionTreeRunResult->getFinalTree());
        $verdictPromptText = $agentEvalJudgedRunService->modelCalls[1]['promptText'];
        self::assertStringContainsString("FINAL TREE:\nL1 [0–7] 8msg rollup:", $verdictPromptText);
        self::assertStringContainsString('TEXT ASSISTANT: an answer a worker stored', $verdictPromptText, 'the judge reads the stored rows, not the raw tail in memory');
    }

    public function testThePreviewOfATreeNodeIsOneLineCutWithAnEllipsis(): void
    {
        $previewText = (new ReflectionMethod(AgentEvalJudgedRunService::class, 'previewText'))->invoke(new AgentEvalJudgedRunService(), "a  long\n summary text", 10);

        self::assertSame('a long su…', $previewText);
    }
}
