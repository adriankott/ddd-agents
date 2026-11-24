<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolParallelSafe;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallImageReference;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallImageStoreService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentEarlierToolImageViewRequestDto;
use DDD\Presentation\Mcp\Dtos\MCPToolResponse;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;

/**
 * The pictures of the conversation's own earlier tool results. At a turn change the pictures of older results leave the
 * context (`AI.toolCallImageStore` retention) and leave a placeholder line `[image <id>, WxH — no longer attached; …]`;
 * `viewEarlierToolImage` attaches one of them again, from the picture store, never by running the original tool.
 *
 * The result is a new tool result with the SAME picture reference (store and id), so it is not stored a second time,
 * and it carries the trust of the call that produced the picture (a screenshot of a foreign page stays untrusted).
 */
class AgentImagesMCPPackage extends MCPPackage
{
    public static ?string $name = 'agent.images';

    public static ?string $description = 'See again a picture of an earlier tool result of this conversation that is no longer attached (its placeholder line names the image id).';

    /** @var int The fewest characters of an image id that are accepted (a shorter prefix may be ambiguous) */
    public const int MIN_IMAGE_ID_LENGTH = 8;

    /** Offered only while pictures are stored and leave the context at a turn change (otherwise no placeholder exists). */
    public static function isAvailable(): bool
    {
        try {
            /** @var AgentToolCallImageStoreService $agentToolCallImageStoreService */
            $agentToolCallImageStoreService = DDDService::instance()->getService(AgentToolCallImageStoreService::class);
            $settings = $agentToolCallImageStoreService->getSettings();
            return $settings->enabled && $settings->retentionEnabled;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Attaches again the picture of an earlier tool result of THIS conversation that left the context — the placeholder
     * line `[image <id>, WxH — no longer attached; …]` names its id. Use it only when you must look at that picture
     * again; the text of the original result is still in the context.
     *
     * @example {"imageId": "3f9a0c1b7d2e"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('one read of the picture store')]
    #[MCPToolSummary('Attach again a picture of an earlier tool result that is no longer attached (the placeholder line names its image id). Never runs the original tool.')]
    public function viewEarlierToolImage(
        AgentEarlierToolImageViewRequestDto $requestDto,
        AgentToolCallProvenance $callProvenance
    ): MCPToolResponse {
        $imageId = strtolower(trim($requestDto->imageId));
        if (strlen($imageId) < static::MIN_IMAGE_ID_LENGTH || !ctype_xdigit($imageId)) {
            return MCPToolResponse::error('viewEarlierToolImage needs the image id of a placeholder line (12 hexadecimal characters).');
        }
        $earlierToolCall = $this->findToolCallHoldingImage($this->findConversationMessages($callProvenance), $imageId);
        $toolCallImageReference = $earlierToolCall !== null ? $this->findImageReferenceOfToolCall($earlierToolCall, $imageId) : null;
        if ($earlierToolCall === null || $toolCallImageReference === null) {
            return MCPToolResponse::error("No earlier tool result of this conversation holds the image {$imageId}.");
        }
        $toolResultImage = $this->readToolCallImage($toolCallImageReference, $callProvenance);
        if ($toolResultImage === null) {
            return MCPToolResponse::error(
                "The image {$imageId} is no longer stored; call the tool that produced it (" . ($earlierToolCall->name ?? 'unknown') . ') again if you need it.'
            );
        }
        $mcpToolResponse = new MCPToolResponse([]);
        $mcpToolResponse->addTextContent(sprintf(
            'The image %s (%dx%d) of the earlier result of %s, attached again.',
            $toolCallImageReference->getShortImageId(),
            $toolCallImageReference->width,
            $toolCallImageReference->height,
            $earlierToolCall->name ?? 'a tool'
        ));
        $mcpToolResponse->addImageContent(
            $toolResultImage->base64Data,
            $toolResultImage->mimeType,
            $toolCallImageReference->sourceStore,
            $toolCallImageReference->sourceImageId,
            $toolCallImageReference->isDurable
        );
        // the picture keeps the trust of the call that produced it; an unknown trust counts as untrusted
        $mcpToolResponse->setDeclaredTrustedContent($earlierToolCall->returnedUntrustedContent === false);
        return $mcpToolResponse;
    }

    /** The conversation's messages: the live set of the call, else the conversation's. */
    protected function findConversationMessages(AgentToolCallProvenance $callProvenance): ?AIConversationMessages
    {
        if ($callProvenance->conversationMessages !== null) {
            return $callProvenance->conversationMessages;
        }
        $conversation = $callProvenance->conversation;
        return $conversation !== null && isset($conversation->messages) ? $conversation->messages : null;
    }

    /** The newest tool call of the conversation that holds a re-resolvable picture whose sha256 starts with the image id. */
    protected function findToolCallHoldingImage(?AIConversationMessages $conversationMessages, string $imageId): ?ToolCall
    {
        if ($conversationMessages === null) {
            return null;
        }
        $messages = $conversationMessages->getElements();
        for ($position = count($messages) - 1; $position >= 0; $position--) {
            $toolCall = isset($messages[$position]->toolCall) ? $messages[$position]->toolCall : null;
            if ($toolCall instanceof ToolCall && $this->findImageReferenceOfToolCall($toolCall, $imageId) !== null) {
                return $toolCall;
            }
        }
        return null;
    }

    /** The re-resolvable picture reference of a tool call whose sha256 starts with the image id. */
    protected function findImageReferenceOfToolCall(ToolCall $toolCall, string $imageId): ?ToolCallImageReference
    {
        foreach ($toolCall->imageReferences as $toolCallImageReference) {
            if ($toolCallImageReference instanceof ToolCallImageReference
                && str_starts_with($toolCallImageReference->sha256, $imageId)
                && $toolCallImageReference->isReResolvable()
            ) {
                return $toolCallImageReference;
            }
        }
        return null;
    }

    /** The bytes of a reference: the picture store for its own pictures, else the store package that declared it. */
    protected function readToolCallImage(ToolCallImageReference $toolCallImageReference, AgentToolCallProvenance $callProvenance): ?ToolResultImage
    {
        if ($toolCallImageReference->sourceStore === ToolCallImageReference::SOURCE_STORE_SPOOL) {
            /** @var AgentToolCallImageStoreService $agentToolCallImageStoreService */
            $agentToolCallImageStoreService = DDDService::instance()->getService(AgentToolCallImageStoreService::class);
            return $agentToolCallImageStoreService->readStoredToolCallImage($toolCallImageReference);
        }
        $conversation = $callProvenance->conversation;
        if ($conversation === null) {
            return null;
        }
        /** @var MCPToolExecutorService $mcpToolExecutorService */
        $mcpToolExecutorService = DDDService::instance()->getService(MCPToolExecutorService::class);
        return $mcpToolExecutorService->resolveToolCallImage($toolCallImageReference, $conversation->findLoadedPackages());
    }
}
