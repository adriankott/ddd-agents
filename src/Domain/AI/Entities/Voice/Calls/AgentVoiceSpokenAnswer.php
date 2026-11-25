<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Domain\AI\Entities\Voice\Prompts\AgentVoiceAIPromptNames;
use DDD\Domain\AI\Entities\Voice\Prompts\AgentVoicePromptText;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The text a voice model may say for one backend answer of a call. A widget block is never handed to the voice (the
 * stored chat row keeps it): the blocks are removed, and when nothing or only lead-in headings are left ("Here's the
 * weather:"), the voice says where the content is instead: in the chat on the caller's screen. English like every
 * hand-over to the voice; the voice model says it in the call language.
 *
 * The session worker passes the answer text and the medium of the call ({@see AgentVoiceCall::getChannelType()}).
 *
 * Override point of an application's subclass at the same path: where the caller finds the content on a further
 * medium of the application ({@see getShownOnlyInTheChatPromptName()}).
 */
class AgentVoiceSpokenAnswer extends ValueObject
{
    /** @var string The lead-in when a widget-only answer has none of its own. */
    public const string DEFAULT_LEAD_IN_LINE = 'Here it is';

    /** @var string The text handed to the voice model. */
    public string $spokenText;

    /** @var bool The answer's content is in widget blocks only: {@see $spokenText} says where the caller finds it. */
    public bool $isShownOnlyInTheChat;

    public function __construct(string $spokenText, bool $isShownOnlyInTheChat = false)
    {
        parent::__construct();
        $this->spokenText = $spokenText;
        $this->isShownOnlyInTheChat = $isShownOnlyInTheChat;
    }

    /**
     * The spoken answer of a backend answer text: its widget blocks removed, or the "shown in the chat" sentence when
     * nothing or only lead-in lines remain once they are gone.
     */
    public static function fromAnswerText(string $answerText, string $channelType): static
    {
        $answerWidgetBlocks = WidgetBlocks::fromMarkdown($answerText);
        $spokenText = trim($answerWidgetBlocks->buildMarkdownWithoutBlocks());
        $leadInLines = $answerWidgetBlocks->getLeadInLinesWhenTheBlocksCarryTheContent();
        if ($answerWidgetBlocks->getWidgetJsons() !== [] && ($spokenText === '' || $leadInLines !== [])) {
            return new static(
                static::buildSentenceShownOnlyInTheChat($leadInLines[0] ?? static::DEFAULT_LEAD_IN_LINE, $channelType),
                isShownOnlyInTheChat: true
            );
        }

        return new static($spokenText);
    }

    /** The lead-in without its colon, then where the caller finds the content. */
    protected static function buildSentenceShownOnlyInTheChat(string $leadInLine, string $channelType): string
    {
        return AgentVoicePromptText::forName(
            static::getShownOnlyInTheChatPromptName($channelType),
            ['LEAD_IN' => rtrim($leadInLine, " :")]
        );
    }

    /**
     * The prompt of the sentence for an answer that is shown in the chat only. The base knows the application's
     * window, where the chat is on the caller's screen; an application with a further medium names its own prompt
     * for that medium here.
     */
    protected static function getShownOnlyInTheChatPromptName(string $channelType): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_ANSWER_SHOWN_IN_THE_CHAT;
    }
}
