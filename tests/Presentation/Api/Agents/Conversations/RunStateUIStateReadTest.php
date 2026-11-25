<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Api\Agents\Conversations;

use DDD\Domain\AI\Entities\ClientTools\AgentClientPresence;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationRunStateGetRequestDto;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The UI state a client reports on a run-state read, and the one stated in its presence, are read back into an
 * `AgentUIState`; a value that is no JSON object is ignored.
 */
class RunStateUIStateReadTest extends UnitTestCase
{
    public function testTheRunStateReadCarriesTheReportedUIState(): void
    {
        $requestDto = new AIConversationRunStateGetRequestDto();
        $requestDto->uiStateJson = '{"routePath":"/orders/42","visible":true}';

        $agentUIState = $requestDto->findUIState();

        self::assertNotNull($agentUIState);
        self::assertSame('/orders/42', $agentUIState->routePath);
        self::assertTrue($agentUIState->visible);
    }

    public function testAValueThatIsNoJsonObjectIsIgnored(): void
    {
        $requestDto = new AIConversationRunStateGetRequestDto();
        $requestDto->uiStateJson = '["/orders/42"]';
        self::assertNull($requestDto->findUIState());
        $requestDto->uiStateJson = 'not json';
        self::assertNull($requestDto->findUIState());
    }

    public function testTheStatedUIStateOfAPresenceIsReadBack(): void
    {
        $agentClientPresence = new AgentClientPresence();
        $agentClientPresence->uiStateJson = '{"routePath":"/orders/42","tab":"items"}';

        self::assertSame('items', $agentClientPresence->getUIState()?->tab);
    }
}
