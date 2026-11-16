<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;

/**
 * A completion request whose load the provider refuses, without a network call: like a real failed load it does not
 * throw, answers no text and keeps the refusal as its failure reason.
 */
class ArgusAIAgentConversationRefusedLoadDouble extends ArgusAIAgentConversation
{
    /** @var string The refusal the load reports */
    public string $refusalReason = 'http 404 from the upstream call: No endpoints found';

    public function argusLoad(
        bool $useArgusEntityCache = true,
        bool $useApiACallCache = true,
        bool $displayCall = false,
        bool $displayResponse = false,
        bool $autoloadCurrentObject = true,
        ?int $apiCallCacheLifetime = null
    ): void {
        $this->lastLoadFailureReason = $this->refusalReason;
    }
}
