<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\AgentSpaces;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentPausedTurnRecord;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Tests\Agents\Domain\AI\Services\AgentSpaces\Probes\AgentSpacesServiceWithArgumentsPerSpaceDouble;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionMethod;

/**
 * The arguments the space of a conversation fills are decided per conversation and space, and the read dedup line
 * has a strip list of its own: an application whose spaces fill different arguments by kind overrides the service,
 * not the conversation.
 */
class AgentSpacesServiceArgumentsOfConversationTest extends IntegrationTestCase
{
    public function testTheDefaultsAreTheSpaceIndependentOnes(): void
    {
        $agentSpacesService = new AgentSpacesService();
        $conversation = new AIConversation();

        self::assertSame(
            $agentSpacesService->getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace(),
            $agentSpacesService->getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpaceOfConversation($conversation, null)
        );
        self::assertSame([AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID], $agentSpacesService->getToolArgumentNamesLeftOutOfTheReadDedupLine());
    }

    public function testTheConversationAsksWithItselfAndItsLoadedSpace(): void
    {
        $agentSpacesService = new AgentSpacesServiceWithArgumentsPerSpaceDouble();
        $this->replaceService(AgentSpacesService::class, $agentSpacesService);
        $projectConversation = $this->buildConversationOfSpace(31, AgentSpacesServiceWithArgumentsPerSpaceDouble::PROJECT_SPACE_ID);
        $otherConversation = $this->buildConversationOfSpace(32, 4);

        self::assertSame([AgentSpacesServiceWithArgumentsPerSpaceDouble::PROJECT_ARGUMENT], array_keys($projectConversation->getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace()));
        self::assertSame([AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID], array_keys($otherConversation->getDescriptionPrefixesOfToolArgumentsFilledFromAgentSpace()));
        self::assertSame([[31, 9], [32, 4]], $agentSpacesService->askedConversationAndSpaceIds);
    }

    public function testTheReadDedupLineLeavesOutTheArgumentsOfItsOwnList(): void
    {
        $this->replaceService(AgentSpacesService::class, new AgentSpacesServiceWithArgumentsPerSpaceDouble());

        self::assertSame(
            '{"query":"open tickets"}',
            (string)(new ReflectionMethod(AgentPausedTurnRecord::class, 'normalisedArguments'))->invoke(null, ['projectId' => 5, 'agentSpaceId' => 9, 'query' => 'open tickets'])
        );
    }

    protected function buildConversationOfSpace(int $conversationId, int $agentSpaceId): AIConversation
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = $agentSpaceId;
        $conversation = new AIConversation();
        $conversation->id = $conversationId;
        $conversation->agentSpaceId = $agentSpaceId;
        $conversation->agentSpace = $agentSpace;
        return $conversation;
    }
}
