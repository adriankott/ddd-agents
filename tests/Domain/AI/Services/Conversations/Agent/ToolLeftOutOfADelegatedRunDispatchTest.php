<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\LoadableSubSkillTools;
use DDD\Domain\AI\Entities\Conversations\Agent\OfferedSkillCatalog;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadToolTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentOfferedTools;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentOfferedToolsInputs;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentRepeatedToolCallCheck;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentToolCallAnswer;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\AI\Services\Conversations\Agent\AgentChildTurnRunnerInterface;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\Probes\ExecutionRecordingDispatchService;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\Probes\ToolSurfaceStubbingConversation;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A tool that needs the owner's own words as evidence (the package's `grantMandate`) called BY NAME inside a delegated
 * run: the tool is off that run's wire, and a call of it is refused before it executes, finally — the child holds only
 * its task, never the owner's words. The conversation with the owner still executes it. The fork admission does not
 * fork such a call, and the `load_tool` catalog never lists it among the sub-skill tools.
 */
class ToolLeftOutOfADelegatedRunDispatchTest extends UnitTestCase
{
    protected const string REFUSAL_TEXT = 'Not executed: `grantMandate` needs the owner\'s own words as evidence, and a sub-agent sees only '
        . 'its task, never the conversation with the owner. The main conversation with the owner makes this call. Nothing was done. '
        . 'Finish the rest of your task and name this open step in your answer.';

    public function testADelegatedRunCallingTheToolByNameIsRefusedAndNothingExecutes(): void
    {
        $callRow = $this->grantCallRow(1);
        $dispatchService = new ExecutionRecordingDispatchService();

        $agentToolCallAnswer = $this->dispatch($dispatchService, $this->conversation(88, $callRow), $callRow);

        self::assertSame([], $dispatchService->executedToolNames, 'nothing executes');
        self::assertSame(self::REFUSAL_TEXT, $callRow->toolCall->result);
        self::assertSame(AIConversationMessage::STATUS_FAILED, $callRow->toolCall->status);
        self::assertTrue($callRow->toolCall->isRefusedAsFinal(), 'the same call meets the same refusal');
        self::assertFalse($callRow->toolCall->wasRefusedByGate, 'no gate that opens later');
        self::assertFalse($agentToolCallAnswer->forceToolLessAnswerStep);
        self::assertSame(self::REFUSAL_TEXT, $dispatchService->buildRefusalOfToolLeftOutOfADelegatedRun('grantMandate'));
    }

    public function testTheIdenticalRepeatInTheDelegatedRunIsAnsweredWithTheRefusal(): void
    {
        $refusedRow = $this->grantCallRow(1);
        $dispatchService = new ExecutionRecordingDispatchService();
        $this->dispatch($dispatchService, $this->conversation(88, $refusedRow), $refusedRow);
        $repeatRow = $this->grantCallRow(2);

        $agentToolCallAnswer = $this->dispatch($dispatchService, $this->conversation(88, $refusedRow, $repeatRow), $repeatRow);

        self::assertSame([], $dispatchService->executedToolNames);
        self::assertStringContainsString('«' . self::REFUSAL_TEXT . '»', (string)$repeatRow->toolCall->result);
        self::assertTrue($agentToolCallAnswer->forceToolLessAnswerStep);
    }

    public function testTheConversationWithTheOwnerExecutesTheTool(): void
    {
        $callRow = $this->grantCallRow(1);
        $dispatchService = new ExecutionRecordingDispatchService();

        $this->dispatch($dispatchService, $this->conversation(null, $callRow), $callRow);

        self::assertSame(['grantMandate'], $dispatchService->executedToolNames);
        self::assertSame(AIConversationMessage::STATUS_COMPLETED, $callRow->toolCall->status);
        self::assertFalse($callRow->toolCall->isRefusedAsFinal());
    }

    public function testTheForkAdmissionDoesNotForkTheCallInADelegatedRun(): void
    {
        $loadedToolNames = ['grantMandate', 'listMandates'];
        $candidate = $this->grantCallRow(1)->toolCall;

        self::assertTrue(AgentRepeatedToolCallCheck::forToolCall($candidate, $this->conversation(88))->wouldInterceptBeforeAppend($loadedToolNames));
        self::assertFalse(AgentRepeatedToolCallCheck::forToolCall($candidate, $this->conversation(null))->wouldInterceptBeforeAppend($loadedToolNames));
    }

    /** `load_tool` naming the grant tool while it is resident on the row (its skill loaded with every tool). */
    public function testALoadOfTheResidentToolInADelegatedRunAnswersTheRefusal(): void
    {
        $loadRow = $this->loadToolCallRow(['grantMandate'], 1, true);
        $conversation = $this->stubbingConversation(88, $loadRow);

        $this->dispatch(new ExecutionRecordingDispatchService(), $conversation, $loadRow);

        self::assertSame(self::REFUSAL_TEXT, $loadRow->toolCall->result, 'never "Already in your tool list"');
        self::assertSame(['grantMandate', 'listMandates'], $loadRow->getLoadedCapabilities()->getLoadedToolNames(), 'nothing changed');
    }

    /** `load_tool` naming the grant tool while only its skill is loaded: the tool is not promoted. */
    public function testALoadOfTheToolOfALoadedSkillInADelegatedRunPromotesNothing(): void
    {
        $loadRow = $this->loadToolCallRow(['grantMandate'], 1, false);
        $conversation = $this->stubbingConversation(88, $loadRow);

        $this->dispatch(new ExecutionRecordingDispatchService(), $conversation, $loadRow);

        self::assertSame(self::REFUSAL_TEXT, $loadRow->toolCall->result, 'never "Tools loaded"');
        self::assertNotContains('grantMandate', $loadRow->getLoadedCapabilities()->getLoadedToolNames());
    }

    /** `load_tool` naming the grant tool of a sub-skill that is not loaded: the sub-skill is not loaded for it. */
    public function testALoadOfTheToolOfAnUnloadedSubSkillInADelegatedRunLoadsNoSubSkill(): void
    {
        $loadRow = $this->loadToolCallRow(['grantMandate'], 1, false);
        $loadRow->loadedCapabilities = LoadedCapabilities::fromCanonicalEntries([
            'Assistant.Overview' => ['name' => 'Assistant.Overview', 'type' => LoadedCapability::TYPE_SKILL],
        ]);
        $conversation = $this->stubbingConversation(88, $loadRow);
        $conversation->stubbedLoadableSubSkillTools = new LoadableSubSkillTools();
        $conversation->stubbedLoadableSubSkillTools->addToolOfSubSkillOnce('grantMandate', 'Agent.Mandates', 'Grant a standing mandate');

        $this->dispatch(new ExecutionRecordingDispatchService(), $conversation, $loadRow);

        self::assertSame(self::REFUSAL_TEXT, $loadRow->toolCall->result, 'never "Loaded the sub-skill"');
        self::assertSame(['Assistant.Overview'], $loadRow->getLoadedCapabilities()->getLoadedSkillNames());
    }

    /** A tool the purpose of the tree blocks, named to `load_tool` in the conversation with the owner. */
    public function testALoadOfAToolThePurposeBlocksAnswersThatItIsNotAvailable(): void
    {
        $loadRow = $this->loadToolCallRow(['listMandates'], 1, false);
        $conversation = $this->stubbingConversation(null, $loadRow);
        /** @var AIConversationPurpose $purpose */
        $purpose = self::instantiateWithoutConstructor(AIConversationPurpose::class);
        $purpose->blockedToolNames = ['listMandates'];
        $conversation->stubbedPurposeOfConversationTree = $purpose;

        $this->dispatch(new ExecutionRecordingDispatchService(), $conversation, $loadRow);

        self::assertSame('Not available in this conversation: `listMandates`. It cannot be loaded or called here; continue without it.', $loadRow->toolCall->result);
        self::assertNotContains('listMandates', $loadRow->getLoadedCapabilities()->getLoadedToolNames());
    }

    /** One blocked and one loadable tool: the loadable one is promoted, the blocked one named in its own sentence. */
    public function testAMixedLoadPromotesTheOfferedToolAndNamesTheBlockedOne(): void
    {
        $loadRow = $this->loadToolCallRow(['grantMandate', 'listMandates'], 1, false);
        $conversation = $this->stubbingConversation(88, $loadRow);
        $conversation->stubbedLoadableSubSkillTools = new LoadableSubSkillTools();

        $this->dispatch(new ExecutionRecordingDispatchService(), $conversation, $loadRow);

        self::assertSame('Tools loaded; their full schemas are now available. ' . self::REFUSAL_TEXT, $loadRow->toolCall->result);
        $loadedToolNames = $loadRow->getLoadedCapabilities()->getLoadedToolNames();
        self::assertContains('listMandates', $loadedToolNames);
        self::assertNotContains('grantMandate', $loadedToolNames);
    }

    /**
     * A `load_tool` call row in a conversation that loaded the module's mandates skill — with its grant and list tools
     * resident when `$toolsAreResident`, the skill alone otherwise.
     *
     * @param string[] $requestedToolNames
     */
    protected function loadToolCallRow(array $requestedToolNames, int $index, bool $toolsAreResident): AIConversationMessage
    {
        $callRow = $this->row(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, '', $index);
        $callRow->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $loadedCapabilityEntries = ['Agent.Mandates' => ['name' => 'Agent.Mandates', 'type' => LoadedCapability::TYPE_SKILL]];
        if ($toolsAreResident) {
            $loadedCapabilityEntries['grantMandate'] = ['name' => 'grantMandate', 'type' => LoadedCapability::TYPE_TOOL, 'parentSkillNames' => ['Agent.Mandates']];
            $loadedCapabilityEntries['listMandates'] = ['name' => 'listMandates', 'type' => LoadedCapability::TYPE_TOOL, 'parentSkillNames' => ['Agent.Mandates']];
        }
        $callRow->loadedCapabilities = LoadedCapabilities::fromCanonicalEntries($loadedCapabilityEntries);
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_' . $index;
        $toolCall->name = LoadToolTool::NAME;
        $toolCall->arguments = ['tool_names' => $requestedToolNames];
        $callRow->toolCall = $toolCall;
        return $callRow;
    }

    /** {@see conversation()} with its tree purpose and its sub-skill tools given. */
    protected function stubbingConversation(?int $delegatedFromMessageId, AIConversationMessage ...$callRows): ToolSurfaceStubbingConversation
    {
        $messages = new AIConversationMessages();
        $firstRow = $this->row(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'allow the weekly post', 0);
        $messages->add($firstRow);
        foreach ($callRows as $callRow) {
            $messages->add($callRow);
        }
        /** @var ToolSurfaceStubbingConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(ToolSurfaceStubbingConversation::class);
        $conversation->messages = $messages;
        $conversation->delegatedFromMessageId = $delegatedFromMessageId;
        return $conversation;
    }

    public function testTheLoadToolCatalogLeavesTheBlockedToolOutOfTheSubSkillTools(): void
    {
        $loadableSubSkillTools = new LoadableSubSkillTools();
        $loadableSubSkillTools->addToolOfSubSkillOnce('grantMandate', 'Agent.Mandates', 'Grant a standing mandate');
        $loadableSubSkillTools->addToolOfSubSkillOnce('listMandates', 'Agent.Mandates', 'List the mandates');
        $agentOfferedToolsInputs = new AgentOfferedToolsInputs();
        $agentOfferedToolsInputs->allowThreads = false;
        $agentOfferedToolsInputs->loadedSkillToolDefinitions = new ToolDefinitions();
        $agentOfferedToolsInputs->loadableSubSkillTools = $loadableSubSkillTools;
        /** @var OfferedSkillCatalog $offeredSkillCatalog */
        $offeredSkillCatalog = self::instantiateWithoutConstructor(OfferedSkillCatalog::class);
        $offeredSkillCatalog->skills = new AISkills();
        $offeredSkillCatalog->alreadyLoadedSkillNames = [];

        $unblockedOffer = AgentOfferedTools::buildFor($offeredSkillCatalog, $agentOfferedToolsInputs);
        self::assertStringContainsString('grantMandate', $this->loadToolDescription($unblockedOffer));
        self::assertTrue($unblockedOffer->offersToolForASubAgentToolRun('grantMandate'));

        $agentOfferedToolsInputs->purposeBlockedToolNames = ['grantMandate'];
        $blockedOffer = AgentOfferedTools::buildFor($offeredSkillCatalog, $agentOfferedToolsInputs);
        $loadToolDescription = $this->loadToolDescription($blockedOffer);
        self::assertStringNotContainsString('grantMandate', $loadToolDescription);
        self::assertStringContainsString('listMandates', $loadToolDescription);
        self::assertFalse($blockedOffer->offersToolForASubAgentToolRun('grantMandate'));
        self::assertSame(['listMandates'], $loadableSubSkillTools->withoutToolNames(['grantMandate'])->getToolNames());
        self::assertSame(['grantMandate', 'listMandates'], $loadableSubSkillTools->getToolNames(), 'the inputs stay as they are');
    }

    protected function loadToolDescription(AgentOfferedTools $agentOfferedTools): string
    {
        foreach ($agentOfferedTools->toOpenAiToolSchemas() as $toolSchema) {
            if (($toolSchema['function']['name'] ?? null) === LoadToolTool::NAME) {
                return json_encode($toolSchema, JSON_THROW_ON_ERROR);
            }
        }
        return '';
    }

    protected function dispatch(ExecutionRecordingDispatchService $dispatchService, AIConversation $conversation, AIConversationMessage $callRow): AgentToolCallAnswer
    {
        return $dispatchService->dispatchToolCall(
            AgentRun::forTurnEntry(),
            $this->createStub(AgentChildTurnRunnerInterface::class),
            $conversation,
            $callRow->toolCall,
            $callRow,
            false,
            null,
            AgentTransientMessageIds::startingAt(0),
            null,
            false
        );
    }

    /** A `grantMandate` call row whose capability set holds the module's mandates skill and its tools. */
    protected function grantCallRow(int $index): AIConversationMessage
    {
        $callRow = $this->row(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, '', $index);
        $callRow->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $callRow->loadedCapabilities = LoadedCapabilities::fromCanonicalEntries([
            'Agent.Mandates' => ['name' => 'Agent.Mandates', 'type' => LoadedCapability::TYPE_SKILL],
            'grantMandate' => ['name' => 'grantMandate', 'type' => LoadedCapability::TYPE_TOOL, 'parentSkillNames' => ['Agent.Mandates']],
            'listMandates' => ['name' => 'listMandates', 'type' => LoadedCapability::TYPE_TOOL, 'parentSkillNames' => ['Agent.Mandates']],
        ]);
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_' . $index;
        $toolCall->name = 'grantMandate';
        $toolCall->arguments = ['actionClass' => 'PUBLISH', 'ownerStatement' => 'publish the weekly post on your own'];
        $callRow->toolCall = $toolCall;
        return $callRow;
    }

    protected function row(string $role, string $authorType, string $text, int $index): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = $role;
        $message->authorType = $authorType;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = $text;
        $message->indexInConversation = $index;
        return $message;
    }

    /** A conversation whose first row is its task or the owner's message; `$delegatedFromMessageId` set = a delegated run. */
    protected function conversation(?int $delegatedFromMessageId, AIConversationMessage ...$callRows): AIConversation
    {
        $messages = new AIConversationMessages();
        $firstRow = $this->row(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'allow the weekly post', 0);
        $messages->add($firstRow);
        foreach ($callRows as $callRow) {
            $messages->add($callRow);
        }
        /** @var AIConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(AIConversation::class);
        $conversation->messages = $messages;
        $conversation->delegatedFromMessageId = $delegatedFromMessageId;
        return $conversation;
    }
}
