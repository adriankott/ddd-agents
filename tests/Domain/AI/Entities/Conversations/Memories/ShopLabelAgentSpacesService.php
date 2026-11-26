<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Memories;

use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;

/**
 * The agent spaces service of an application that knows a space as a shop with an id of its own: a text for the model
 * names the space `shop:<the shop's id>`. Records the id of every space it was asked about.
 */
class ShopLabelAgentSpacesService extends AgentSpacesService
{
    /** @var array<int, int> The id of the shop by the id of its space */
    public array $shopIdsByAgentSpaceId = [];

    /** @var int[] The space ids asked for, in order */
    public array $askedAgentSpaceIds = [];

    public function buildAgentSpaceLabelForTheModel(int $agentSpaceId): string
    {
        $this->askedAgentSpaceIds[] = $agentSpaceId;
        return 'shop:' . ($this->shopIdsByAgentSpaceId[$agentSpaceId] ?? 0);
    }
}
