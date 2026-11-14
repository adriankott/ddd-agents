<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks an #[MCPTool] method whose call is judged on the owner's OWN words in the conversation's transcript (a grant
 * the server verifies against the dialogue). A sub-agent started by `delegate` works on a child conversation that holds
 * only its task text, never the owner's words, so it never makes such a call. The readers ask for it by tool name
 * ({@see MCPToolAttributeCatalog}):
 *
 * - the offer: a delegated run is never offered such a tool
 *   ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::findToolNamesLeftOutOfADelegatedRun()});
 * - the dispatch: a delegated run that calls such a tool by name is refused, finally, and nothing runs
 *   ({@see \DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallDispatchService::buildRefusalOfToolLeftOutOfADelegatedRun()});
 * - the `delegate` spawn: a tool run of such a tool is refused; the conversation with the owner makes the call
 *   ({@see \DDD\Domain\AI\Services\Conversations\Agent\AIConversationDelegationSpawnService}).
 *
 * The catalog reads the method of the class an application binds: an application that declares the tool on its own
 * package class repeats the attribute there.
 *
 *   #[MCPTool, MCPToolRequiresOwnerWordsAsEvidence('the grant is verified against the owner\'s statement')]
 *   public function grantPermission(GrantPermissionRequestDto $requestDto): GrantPermissionResponseDto {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPToolRequiresOwnerWordsAsEvidence
{
    public function __construct(public string $reason = '')
    {
    }
}
