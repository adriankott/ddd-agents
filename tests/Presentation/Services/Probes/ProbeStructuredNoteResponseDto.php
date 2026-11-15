<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services\Probes;

use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponseDictatedWidgetTypeProvider;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolResponsePlatformNoteProvider;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** A structured tool result (no markdown rendering) with a platform note and a dictated widget type. */
class ProbeStructuredNoteResponseDto extends RestResponseDto implements MCPToolResponsePlatformNoteProvider, MCPToolResponseDictatedWidgetTypeProvider
{
    /** @var string The query the result answers. */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $query = 'bicycle';

    public function buildPlatformNoteForModel(): ?string
    {
        return 'Copy the block with groupsFromToolCallId unchanged.';
    }

    public function getDictatedWidgetType(): ?string
    {
        return 'ConversationSearchResults';
    }
}
