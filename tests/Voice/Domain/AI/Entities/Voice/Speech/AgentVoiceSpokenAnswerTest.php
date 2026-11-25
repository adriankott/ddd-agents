<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\Speech;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSpokenAnswer;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;

/**
 * The text a voice may say for a backend answer: widget blocks never reach the voice, and an answer whose content is
 * in widget blocks only says that it is shown in the chat on the caller's screen, led in by the answer's own heading
 * (without its colon) or the default lead-in.
 */
class AgentVoiceSpokenAnswerTest extends AgentVoiceUnitTestCase
{
    protected const string FORECAST_WIDGET_BLOCK = "```widget\n{\"type\":\"Forecast\",\"days\":3}\n```";

    protected const string SHOWN_IN_THE_CHAT_TAIL = ' — it is shown in the chat on your screen. Say that in one sentence; do not list or guess what it shows.';

    public function testAWidgetOnlyAnswerSaysItIsShownInTheChatWithTheDefaultLeadIn(): void
    {
        $agentVoiceSpokenAnswer = AgentVoiceSpokenAnswer::fromAnswerText(static::FORECAST_WIDGET_BLOCK, AgentVoiceCall::CHANNEL_TYPE_APP);

        static::assertTrue($agentVoiceSpokenAnswer->isShownOnlyInTheChat);
        static::assertSame('Here it is' . static::SHOWN_IN_THE_CHAT_TAIL, $agentVoiceSpokenAnswer->spokenText);
    }

    public function testADoubledHeadingBecomesOneLeadInWithoutItsColon(): void
    {
        $answerText = "Here's the forecast for the week:\n\n" . static::FORECAST_WIDGET_BLOCK . "\n\n**Here's the forecast for the week:**";

        $agentVoiceSpokenAnswer = AgentVoiceSpokenAnswer::fromAnswerText($answerText, AgentVoiceCall::CHANNEL_TYPE_APP);

        static::assertTrue($agentVoiceSpokenAnswer->isShownOnlyInTheChat);
        static::assertSame("Here's the forecast for the week" . static::SHOWN_IN_THE_CHAT_TAIL, $agentVoiceSpokenAnswer->spokenText);
    }

    public function testAHeadingBeforeAWidgetLeadsIn(): void
    {
        $agentVoiceSpokenAnswer = AgentVoiceSpokenAnswer::fromAnswerText("Your figures:\n" . static::FORECAST_WIDGET_BLOCK, AgentVoiceCall::CHANNEL_TYPE_APP);

        static::assertTrue($agentVoiceSpokenAnswer->isShownOnlyInTheChat);
        static::assertSame('Your figures' . static::SHOWN_IN_THE_CHAT_TAIL, $agentVoiceSpokenAnswer->spokenText);
    }

    public function testASentenceNextToAWidgetIsKeptAndTheBlockRemoved(): void
    {
        $answerText = "It will be sunny tomorrow, 21 degrees.\n\n" . static::FORECAST_WIDGET_BLOCK;

        $agentVoiceSpokenAnswer = AgentVoiceSpokenAnswer::fromAnswerText($answerText, AgentVoiceCall::CHANNEL_TYPE_APP);

        static::assertFalse($agentVoiceSpokenAnswer->isShownOnlyInTheChat);
        static::assertSame('It will be sunny tomorrow, 21 degrees.', $agentVoiceSpokenAnswer->spokenText);
    }

    public function testAPlainTextAnswerIsSpokenUnchangedOnEveryMedium(): void
    {
        $answerText = "You have 12 new entries this week.\nThree of them need an answer.";

        foreach ([AgentVoiceCall::CHANNEL_TYPE_APP, 'OTHER_MEDIUM'] as $channelType) {
            $agentVoiceSpokenAnswer = AgentVoiceSpokenAnswer::fromAnswerText($answerText, $channelType);

            static::assertFalse($agentVoiceSpokenAnswer->isShownOnlyInTheChat);
            static::assertSame($answerText, $agentVoiceSpokenAnswer->spokenText);
        }
    }

    public function testAnEmptyAnswerIsAnEmptySpokenTextAndNoWidgetSentence(): void
    {
        $agentVoiceSpokenAnswer = AgentVoiceSpokenAnswer::fromAnswerText("  \n", AgentVoiceCall::CHANNEL_TYPE_APP);

        static::assertFalse($agentVoiceSpokenAnswer->isShownOnlyInTheChat);
        static::assertSame('', $agentVoiceSpokenAnswer->spokenText);
    }

    public function testTheBaseNamesTheScreenForEveryMedium(): void
    {
        static::assertSame(
            'Here it is' . static::SHOWN_IN_THE_CHAT_TAIL,
            AgentVoiceSpokenAnswer::fromAnswerText(static::FORECAST_WIDGET_BLOCK, 'OTHER_MEDIUM')->spokenText
        );
    }
}
