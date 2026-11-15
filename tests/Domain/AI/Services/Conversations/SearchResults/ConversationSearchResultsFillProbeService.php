<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\SearchResults;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\Bindings\ComposedViewToolCallResults;
use DDD\Domain\AI\Services\Conversations\ComposedViewBindingResolutionService;

/** The resolution service with the conversation's tool calls given by the test instead of read from the rows. */
class ConversationSearchResultsFillProbeService extends ComposedViewBindingResolutionService
{
    public ComposedViewToolCallResults $givenToolCallResults;

    /** @var string[][] The ids each lookup asked for. */
    public array $askedToolCallIds = [];

    public function __construct()
    {
        $this->givenToolCallResults = new ComposedViewToolCallResults();
    }

    protected function findToolCallResults(?AIConversation $conversation, array $toolCallIds): ComposedViewToolCallResults
    {
        $this->askedToolCallIds[] = $toolCallIds;
        return $this->givenToolCallResults;
    }
}
