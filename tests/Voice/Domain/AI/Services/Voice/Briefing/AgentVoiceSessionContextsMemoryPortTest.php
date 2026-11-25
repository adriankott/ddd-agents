<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Briefing;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;

/**
 * The standing directives of the call's briefing read through the chat's memory port ({@see AgentMemoriesService}):
 * with the package's binding the block is the one the briefing rendered from the memory store before, character for
 * character; with an application's own port bound the block shows that port's memories, and the package's store is
 * not read.
 */
class AgentVoiceSessionContextsMemoryPortTest extends AgentVoiceIntegrationTestCase
{
    protected BriefingAgentMemoryStoreService $briefingAgentMemoryStoreService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([]));
        $this->declareAgentPersonaConfiguration(['agentName' => 'Nova', 'productName' => 'the workbench']);
        $this->declareConfigEntries('AI.agentMemory', ['enabled' => true]);
        $this->briefingAgentMemoryStoreService = new BriefingAgentMemoryStoreService();
        $this->briefingAgentMemoryStoreService->directiveContents = ['Never call before nine.', 'Always confirm the date twice.', 'Use the first name.'];
        $this->replaceService(AgentMemoryStoreService::class, $this->briefingAgentMemoryStoreService);
        $this->replaceService(AgentSpacesService::class, new BriefingAgentSpacesService());
        $this->replaceService(AIConversationEntitlementsService::class, new BriefingAIConversationEntitlementsService());
    }

    public function testWithThePackagesBindingTheBlockIsTheOneRenderedFromTheStoreBefore(): void
    {
        // the block as the briefing rendered it before it read through the port: the store's directives of the scope
        $blockBefore = $this->briefingAgentMemoryStoreService->findCurrentDirectivesForScope(42, 9)->toDirectivesBlock();
        $this->briefingAgentMemoryStoreService->readScopes = [];

        $voiceSessionContext = (new FixedCallLanguageAgentVoiceSessionContextsService())->buildVoiceSessionContext(static::buildConversation(), new AgentVoiceCall());

        static::assertNotSame('', trim($blockBefore));
        static::assertSame($blockBefore, $voiceSessionContext->memoryDirectivesBlock);
        static::assertSame([[42, 9]], $this->briefingAgentMemoryStoreService->readScopes, 'the same scope');
    }

    public function testWithThePackagesBindingAConversationWithoutASpaceReadsTheAccountWideDirectives(): void
    {
        $blockBefore = $this->briefingAgentMemoryStoreService->findCurrentDirectivesForScope(42, null)->toDirectivesBlock();
        $this->briefingAgentMemoryStoreService->readScopes = [];
        $conversation = static::buildConversation();
        unset($conversation->agentSpaceId);
        $conversation->agentSpace = null;

        $voiceSessionContext = (new FixedCallLanguageAgentVoiceSessionContextsService())->buildVoiceSessionContext($conversation, new AgentVoiceCall());

        static::assertSame($blockBefore, $voiceSessionContext->memoryDirectivesBlock);
        static::assertSame([[42, null]], $this->briefingAgentMemoryStoreService->readScopes);
    }

    public function testABoundPortGivesTheBlockAndTheStoreIsNotRead(): void
    {
        // an application that replaced the port wholesale leaves the package's memory switch off
        $this->declareConfigEntries('AI.agentMemory', ['enabled' => false]);
        $boundAgentMemoriesService = new BoundAgentMemoriesService();
        $this->replaceService(AgentMemoriesService::class, $boundAgentMemoriesService);

        $voiceSessionContext = (new FixedCallLanguageAgentVoiceSessionContextsService())->buildVoiceSessionContext(static::buildConversation(), new AgentVoiceCall());

        static::assertSame("\n\n# The application's own standing rules\n- Speak slowly.", $voiceSessionContext->memoryDirectivesBlock);
        static::assertSame([[42, 9]], $boundAgentMemoriesService->readScopes, 'asked for the account and the space of the conversation');
        static::assertSame([], $this->briefingAgentMemoryStoreService->readScopes);
    }

    public function testABoundPortWithoutDirectivesGivesNoBlock(): void
    {
        $boundAgentMemoriesService = new BoundAgentMemoriesService();
        $boundAgentMemoriesService->directivesBlock = "  \n ";
        $this->replaceService(AgentMemoriesService::class, $boundAgentMemoriesService);

        $voiceSessionContext = (new FixedCallLanguageAgentVoiceSessionContextsService())->buildVoiceSessionContext(static::buildConversation(), new AgentVoiceCall());

        static::assertNull($voiceSessionContext->memoryDirectivesBlock);
    }

    protected static function buildConversation(): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->accountId = 42;
        $conversation->agentSpaceId = 9;
        $agentSpace = new AgentSpace();
        $agentSpace->id = 9;
        $conversation->agentSpace = $agentSpace;
        $conversation->messages = new AIConversationMessages();

        return $conversation;
    }
}
