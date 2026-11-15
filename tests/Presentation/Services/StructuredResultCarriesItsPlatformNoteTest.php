<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services;

use DDD\Tests\Agents\Presentation\Services\Probes\ProbeNoteValueObject;
use DDD\Tests\Agents\Presentation\Services\Probes\ProbeStructuredNoteResponseDto;
use DDD\Tests\Agents\Presentation\Services\Probes\ResultRenderingToolExecutor;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A result rendered structured — a response DTO without markdown, or a bare domain object — hands the model its
 * platform note and its dictated widget type like a markdown result does; the note stays out of the stored result.
 */
class StructuredResultCarriesItsPlatformNoteTest extends UnitTestCase
{
    public function testAStructuredResponseDtoCarriesItsNoteAndDictatedWidgetType(): void
    {
        $response = (new ResultRenderingToolExecutor())->callRenderToolResultForModel(new ProbeStructuredNoteResponseDto(), 'searchConversations', false);

        self::assertSame('Copy the block with groupsFromToolCallId unchanged.', $response->getPlatformNoteForModel());
        self::assertSame('ConversationSearchResults', $response->getDictatedWidgetType());
        self::assertStringNotContainsString('groupsFromToolCallId', (string)json_encode($response->structuredContent), 'the note is not part of the stored result');
    }

    public function testADomainObjectResultCarriesItsNote(): void
    {
        $response = (new ResultRenderingToolExecutor())->callRenderToolResultForModel(new ProbeNoteValueObject(), 'probe', true);

        self::assertSame('Read the label as written.', $response->getPlatformNoteForModel());
    }
}
