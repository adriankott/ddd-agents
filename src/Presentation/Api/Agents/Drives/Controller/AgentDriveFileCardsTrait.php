<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Drives\Controller;

use DDD\Domain\AI\Entities\Conversations\Widgets\DriveFilesConversationWidget;
use DDD\Domain\AI\Services\Drives\AgentDriveStoresService;
use DDD\Presentation\Api\Agents\Drives\Dtos\AgentDriveFileCardsRequestDto;
use DDD\Presentation\Api\Agents\Drives\Dtos\AgentDriveFileCardsResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Post;

/**
 * The hydration read of the `DriveFiles` widget: the facts of the block's files from their drive store, under the
 * viewer's rights (name, type, size, date or child count, state). Content URLs come from the store's own REST side
 * (the cloud drive: `POST …/cards`). A host mounts it on its chat controller (`use AgentDriveFileCardsTrait;`).
 */
trait AgentDriveFileCardsTrait
{
    /**
     * The cards of a `DriveFiles` block.
     */
    #[Post('/driveFiles/cards')]
    #[Summary('AgentDriveFile Cards')]
    public function findAgentDriveFileCards(
        AgentDriveFileCardsRequestDto $requestDto,
        AgentDriveStoresService $agentDriveStoresService
    ): AgentDriveFileCardsResponseDto {
        $responseDto = new AgentDriveFileCardsResponseDto();
        $responseDto->files = $agentDriveStoresService->findFileCards(
            $requestDto->store,
            array_slice(array_values(array_map('strval', $requestDto->references)), 0, DriveFilesConversationWidget::MAX_FILES),
            $requestDto->widgetSelfHydrationKey
        );
        return $responseDto;
    }
}
