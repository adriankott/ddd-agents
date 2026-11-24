<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallImageReference;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The image retention cut: the picture results of earlier turns keep their pictures only for the newest N; the current
 * turn's results never leave; the cut of the turn before tells which results leave on this call.
 */
class ImageRetentionCutTest extends UnitTestCase
{
    public function testOnlyTheNewestPictureResultsOfEarlierTurnsKeepTheirPictures(): void
    {
        // turn 1: pictures at 1, 2, 3 · turn 2 (owner at 4): picture at 5 · turn 3 (owner at 6): picture at 7
        $messages = static::buildMessages([0 => 'owner', 1 => 'picture', 2 => 'picture', 3 => 'picture', 4 => 'owner', 5 => 'picture', 6 => 'owner', 7 => 'picture']);

        self::assertSame(2, AgentModelInput::findImageRetentionCutMessageIndex($messages, 3), 'kept: 5, 3, 2 — 1 leaves');
        self::assertSame(5, AgentModelInput::findImageRetentionCutMessageIndex($messages, 1), 'kept: 5');
        self::assertSame(6, AgentModelInput::findImageRetentionCutMessageIndex($messages, 0), 'none kept: the cut sits right above 5');
        self::assertSame(PHP_INT_MIN, AgentModelInput::findImageRetentionCutMessageIndex($messages, 4), 'four earlier results, four kept');
    }

    public function testTheCurrentTurnNeverLosesItsPicturesAndTheTurnBeforeGivesThePreviousCut(): void
    {
        $messages = static::buildMessages([0 => 'owner', 1 => 'picture', 2 => 'picture', 3 => 'owner', 4 => 'picture', 5 => 'picture', 6 => 'picture']);

        // the current turn (from 3) holds three pictures: none of them counts against N
        self::assertSame(2, AgentModelInput::findImageRetentionCutMessageIndex($messages, 1));
        // the turn before (owner at 0) had no earlier picture result: nothing was retired then — 1 and 2 leave now
        self::assertSame(PHP_INT_MIN, AgentModelInput::findImageRetentionCutMessageIndex($messages, 1, 1));
        self::assertSame(PHP_INT_MIN, AgentModelInput::findImageRetentionCutMessageIndex(static::buildMessages([0 => 'picture', 1 => 'picture']), 0), 'no turn initiator: nothing leaves');
    }

    /**
     * @param array<int, string> $rowKinds index => 'owner' | 'picture'
     * @return AIConversationMessage[]
     */
    protected static function buildMessages(array $rowKinds): array
    {
        $messages = [];
        foreach ($rowKinds as $messageIndex => $rowKind) {
            $message = new AIConversationMessage();
            $message->indexInConversation = $messageIndex;
            if ($rowKind === 'owner') {
                $message->role = AIConversationMessage::ROLE_USER;
                $message->authorType = AIConversationMessage::AUTHOR_USER;
            } else {
                $message->role = AIConversationMessage::ROLE_ASSISTANT;
                $toolCall = new ToolCall();
                $toolCall->toolCallId = 'call_' . $messageIndex;
                $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
                $toolCallImageReference = new ToolCallImageReference();
                $toolCallImageReference->sha256 = hash('sha256', (string)$messageIndex);
                $toolCall->imageReferences = [$toolCallImageReference];
                $message->toolCall = $toolCall;
            }
            $messages[] = $message;
        }
        return $messages;
    }
}
