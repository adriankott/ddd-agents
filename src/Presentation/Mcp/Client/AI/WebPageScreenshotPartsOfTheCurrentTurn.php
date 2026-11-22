<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\WebSearch\PageScreenshots\PageScreenshotPart;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;

/**
 * The parts of one page and device that `getWebPageAsScreenshot` already showed in the CURRENT turn, so the tool line
 * can say so: a model whose earlier pictures left its view tends to reopen part 1, 2, 3 again. Read like
 * {@see ToolCallsOfTheCurrentTurn}: the current turn's rows, every call that did not fail or get refused (a failed
 * render showed nothing). The calling row itself is left out by its tool call id; without that id every call that is
 * not COMPLETED yet is left out (the calling row is still pending). A row whose completion has not reached the read
 * set yet still counts. The page is compared by its canonical URL, the device in any letter case. Outside the agent
 * loop there is nothing to read.
 */
class WebPageScreenshotPartsOfTheCurrentTurn
{
    public const string TOOL_NAME = 'getWebPageAsScreenshot';

    /** @return int[] The part numbers already shown, ascending and unique; empty outside the agent loop. */
    public static function findPartNumbersShownInTurn(AgentToolCallProvenance $callProvenance, string $url, string $device): array
    {
        if ($callProvenance->isEvalHarnessCall) {
            return [];
        }
        $conversation = $callProvenance->conversation;
        $messages = $conversation !== null && isset($conversation->messages) ? $conversation->messages : $callProvenance->conversationMessages;
        if (!$messages instanceof AIConversationMessages) {
            return [];
        }
        return self::findPartNumbersShownInMessages($messages, $url, $device, $callProvenance->toolCallId);
    }

    /**
     * @return int[] The part numbers of `$url` on `$device` shown in the current turn of `$messages`, the call
     * `$currentToolCallId` left out. Pure.
     */
    public static function findPartNumbersShownInMessages(AIConversationMessages $messages, string $url, string $device, ?string $currentToolCallId = null): array
    {
        $currentToolCallId = $currentToolCallId !== null && $currentToolCallId !== '' ? $currentToolCallId : null;
        $canonicalUrlKey = WebPageMarkdown::canonicalUrlKey($url);
        $partNumbers = [];
        foreach ($messages->buildCurrentTurn()->getRows() as $message) {
            $toolCall = isset($message->toolCall) ? $message->toolCall : null;
            if ($toolCall === null || ($toolCall->name ?? null) !== self::TOOL_NAME
                || in_array($toolCall->status, [AIConversationMessage::STATUS_FAILED, AIConversationMessage::STATUS_CANCELLED, AIConversationMessage::STATUS_CANCEL_REQUESTED], true)
                || $toolCall->wasRefusedAsDuplicate || $toolCall->wasRefusedByGate
            ) {
                continue;
            }
            $rowToolCallId = (string)($toolCall->toolCallId ?? '');
            $isTheCallingRow = $currentToolCallId !== null
                ? $rowToolCallId === $currentToolCallId
                : $toolCall->status !== AIConversationMessage::STATUS_COMPLETED;
            if ($isTheCallingRow) {
                continue;
            }
            $arguments = $toolCall->arguments;
            $calledUrl = is_string($arguments['url'] ?? null) ? $arguments['url'] : '';
            $calledDevice = is_string($arguments['device'] ?? null) && strtolower(trim($arguments['device'])) === PageScreenshotPart::DEVICE_MOBILE
                ? PageScreenshotPart::DEVICE_MOBILE
                : PageScreenshotPart::DEVICE_DESKTOP;
            if ($calledUrl === '' || $calledDevice !== $device || WebPageMarkdown::canonicalUrlKey($calledUrl) !== $canonicalUrlKey) {
                continue;
            }
            $partNumbers[] = max(1, is_numeric($arguments['part'] ?? null) ? (int)$arguments['part'] : 1);
        }
        $partNumbers = array_values(array_unique($partNumbers));
        sort($partNumbers);
        return $partNumbers;
    }
}
