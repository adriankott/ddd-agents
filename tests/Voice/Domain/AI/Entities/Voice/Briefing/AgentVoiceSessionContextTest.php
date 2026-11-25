<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Entities\Voice\Briefing;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSeedInputMessage;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSessionContext;
use DDD\Domain\AI\Entities\Voice\Prompts\AgentVoiceAIPromptNames;
use DDD\Domain\AI\Entities\Voice\Prompts\AgentVoicePromptText;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use DDD\Tests\Agents\Voice\Support\AgentVoiceValueObjectAssertionsTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the voice model of one call is told: the instructions (opening by direction, task, address, speech delivery
 * with the group words rule, the language rule with the speaking style, the delegation rules, then the application's
 * blocks, empty ones skipped) and the seed rows (the opening row first, then character, standing rules, what was
 * last discussed, product scope, then the application's rows, empty ones skipped). The persona names the agent
 * "Nova"; every text comes from the package's prompt files.
 */
class AgentVoiceSessionContextTest extends AgentVoiceUnitTestCase
{
    use AgentVoiceValueObjectAssertionsTrait;

    protected const string MIRRORED_GERMAN = 'Open the call in German. From then on answer in the language the caller speaks; while you cannot tell, stay in German.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([]));
        $this->declareAgentPersonaConfiguration(['agentName' => 'Nova', 'productName' => 'the product']);
    }

    protected static function buildFullContext(string $callDirection): AgentVoiceSessionContext
    {
        $agentVoiceSessionContext = new AgentVoiceSessionContext();
        $agentVoiceSessionContext->authenticatedAccountId = 42;
        $agentVoiceSessionContext->callDirection = $callDirection;
        $agentVoiceSessionContext->callTask = '  Confirm the new opening hours.  ';
        $agentVoiceSessionContext->ownerAddressing = ' Address the owner as Sam. ';
        $agentVoiceSessionContext->languageInstruction = static::MIRRORED_GERMAN;
        $agentVoiceSessionContext->callLanguageCode = 'de';
        $agentVoiceSessionContext->speakingStyle = ' calm and matter-of-fact ';
        $agentVoiceSessionContext->characterCore = ' Warm and quick. ';
        $agentVoiceSessionContext->memoryDirectivesBlock = ' - Never call before nine. ';
        $agentVoiceSessionContext->episodicHistory = ' The menu update. ';
        $agentVoiceSessionContext->productCapabilities = ' Listings and reviews. ';

        return $agentVoiceSessionContext;
    }

    public function testTheInstructionsOfACallTheOwnerStartedInTheirOrder(): void
    {
        $liveInstructions = static::buildFullContext(AgentVoiceCall::DIRECTION_INBOUND)->composeLiveInstructions();

        static::assertSame(implode("\n\n", [
            "The owner opened this call from inside the application and is signed in (account 42).\n"
            . 'You are Nova, the owner\'s digital assistant. Confirm briefly and warmly that you are connected, then ask what this is about. Open the call yourself — do not wait for the caller to speak first.',
            "Your task for this call:\nConfirm the new opening hours.\n"
            . 'State the reason for the call right in your opening and work the task through directly; anything about the owner\'s data goes to the backend as always. Summarize at the end what was done or agreed.',
            'Address the owner as Sam.',
            AgentVoicePromptText::forName(AgentVoiceAIPromptNames::APP_AI_VOICE_SPEECH_DELIVERY)
            . "\n" . AgentVoiceSessionContext::buildGroupWordsRule('de')
            . "\n" . static::MIRRORED_GERMAN . ' Speaking style set by the owner: calm and matter-of-fact.',
            AgentVoicePromptText::forName(AgentVoiceAIPromptNames::APP_AI_VOICE_DELEGATION_RULES),
        ]), $liveInstructions);
        static::assertStringStartsWith('# The backend — the real Nova agent that does the owner\'s work', AgentVoicePromptText::forName(AgentVoiceAIPromptNames::APP_AI_VOICE_DELEGATION_RULES));
    }

    public function testACallTheAgentStartedOpensWithItsOwnBlock(): void
    {
        $liveInstructions = static::buildFullContext(AgentVoiceCall::DIRECTION_OUTBOUND)->composeLiveInstructions();

        static::assertStringStartsWith(
            'YOU started this call: you rang the owner inside the application, and the person who picked up is signed in as the owner (account 42).'
            . "\nYou are Nova. The owner KNOWS you",
            $liveInstructions
        );
        static::assertStringNotContainsString('The owner opened this call', $liveInstructions);
        static::assertStringContainsString("\n\nYour task for this call:\nConfirm the new opening hours.\n", $liveInstructions);
    }

    public function testEmptyBlocksAreSkippedAndAMissingLanguageRuleFollowsTheCaller(): void
    {
        $agentVoiceSessionContext = new AgentVoiceSessionContext();
        $agentVoiceSessionContext->authenticatedAccountId = 7;
        $agentVoiceSessionContext->callTask = '   ';
        $agentVoiceSessionContext->ownerAddressing = ' ';
        $agentVoiceSessionContext->speakingStyle = '  ';

        static::assertSame(implode("\n\n", [
            AgentVoicePromptText::forName(AgentVoiceAIPromptNames::APP_AI_VOICE_BRIEFING, ['ACCOUNT_ID' => 7]),
            AgentVoicePromptText::forName(AgentVoiceAIPromptNames::APP_AI_VOICE_SPEECH_DELIVERY)
            . "\n" . AgentVoiceSessionContext::buildGroupWordsRule(null)
            . "\nAnswer in the language the caller speaks, and stay in it for the whole call.",
            AgentVoicePromptText::forName(AgentVoiceAIPromptNames::APP_AI_VOICE_DELEGATION_RULES),
        ]), $agentVoiceSessionContext->composeLiveInstructions());
        static::assertNull($agentVoiceSessionContext->buildLiveCallTaskBlock());
        static::assertSame('Answer in the language the caller speaks, and stay in it for the whole call.', AgentVoiceSessionContext::buildLanguageInstructionWithoutSettings());
    }

    public function testACallWithoutAConversationIsToldOneShortText(): void
    {
        $agentVoiceSessionContext = static::buildFullContext(AgentVoiceCall::DIRECTION_INBOUND);
        $agentVoiceSessionContext->isWithoutConversation = true;

        static::assertSame(
            'You are Nova, the owner\'s digital assistant. The owner reached you from inside the application and is signed in. Open the call yourself: greet them briefly and ask what this is about.',
            $agentVoiceSessionContext->composeLiveInstructions()
        );
    }

    /** @return array<string, array{?string, bool}> */
    public static function provideCallLanguagesOfTheGroupWordsRule(): array
    {
        return [
            'no language known yet' => [null, true],
            'German' => ['de', true],
            'German in capitals' => ['DE', true],
            'English' => ['en', false],
            'French' => ['fr', false],
            'a German region code is not German' => ['de-AT', false],
        ];
    }

    #[DataProvider('provideCallLanguagesOfTheGroupWordsRule')]
    public function testTheGermanExamplesOnlyForAGermanCallOrAnUnknownLanguage(?string $callLanguageCode, bool $expectsGermanExamples): void
    {
        $groupWordsRule = AgentVoiceSessionContext::buildGroupWordsRule($callLanguageCode);

        $expectedStart = '- Classic language for groups — the generic masculine, in every language, also when the backend\'s answer uses something else: ';
        static::assertStringStartsWith(
            $expectsGermanExamples ? $expectedStart . 'German "Teilnehmer", ' : $expectedStart . 'French "les clients"',
            $groupWordsRule
        );
        static::assertStringNotContainsString('{%', $groupWordsRule);
        static::assertSame(
            AgentVoicePromptText::forName(AgentVoiceAIPromptNames::APP_AI_VOICE_SPEECH_DELIVERY) . "\n" . $groupWordsRule,
            AgentVoiceSessionContext::buildSpokenDeliveryInstructions($callLanguageCode)
        );
    }

    public function testTheSeedOpensWithTheOpeningRowThenTheBriefingBlocksInOrder(): void
    {
        $seedInputMessages = static::buildFullContext(AgentVoiceCall::DIRECTION_INBOUND)->buildLiveCreationSeedInputMessages();

        static::assertSameValueObjects([
            AgentVoiceSeedInputMessage::create('developer', 'Start of the call: you speak first, right now, before the caller says anything — confirm briefly and warmly that they are connected and ask what this is about, in one or two short sentences. ' . static::MIRRORED_GERMAN . ' Then stop and listen.'),
            AgentVoiceSeedInputMessage::create('developer', "Your character — applies on a call exactly as in the chat:\nWarm and quick."),
            AgentVoiceSeedInputMessage::create('developer', "The owner's standing rules — follow them:\n- Never call before nine."),
            AgentVoiceSeedInputMessage::create('developer', "What was last discussed with the owner:\nThe menu update."),
            AgentVoiceSeedInputMessage::create('developer', "The owner's product scope (context only, do not recite):\nListings and reviews."),
        ], $seedInputMessages);
    }

    public function testTheOpeningRowOfACallTheAgentStartedSaysWhyItCalls(): void
    {
        $agentVoiceSessionContext = new AgentVoiceSessionContext();
        $agentVoiceSessionContext->callDirection = AgentVoiceCall::DIRECTION_OUTBOUND;

        static::assertSameValueObjects(
            AgentVoiceSeedInputMessage::create('developer', 'Start of the call: you speak first, right now, before the caller says anything — greet the person warmly and say why you are calling, in one or two short sentences. Answer in the language the caller speaks, and stay in it for the whole call. Then stop and listen.'),
            $agentVoiceSessionContext->buildLiveOpeningSeedInputMessage()
        );
    }

    public function testEmptyBriefingBlocksGiveNoSeedRow(): void
    {
        $agentVoiceSessionContext = new AgentVoiceSessionContext();
        $agentVoiceSessionContext->characterCore = ' ';
        $agentVoiceSessionContext->memoryDirectivesBlock = null;
        $agentVoiceSessionContext->episodicHistory = "\n";
        $agentVoiceSessionContext->productCapabilities = 'Listings.';

        static::assertSameValueObjects(
            [AgentVoiceSeedInputMessage::create('developer', "The owner's product scope (context only, do not recite):\nListings.")],
            $agentVoiceSessionContext->buildLiveSeedInputMessages()
        );
        static::assertCount(2, $agentVoiceSessionContext->buildLiveCreationSeedInputMessages());
    }

    public function testAPlaceholderInAnOwnersTextIsNotFilledAgain(): void
    {
        $agentVoiceSessionContext = new AgentVoiceSessionContext();
        $agentVoiceSessionContext->callTask = 'Ask about {%AGENT_NAME%} and {%ACCOUNT_ID%}.';

        static::assertStringContainsString("\nAsk about {%AGENT_NAME%} and {%ACCOUNT_ID%}.\n", (string)$agentVoiceSessionContext->buildLiveCallTaskBlock());
    }

    public function testAnApplicationAddsBlocksAndSeedRowsAfterThePackagesOwn(): void
    {
        $applicationContext = new ApplicationAgentVoiceSessionContext();
        $applicationContext->workspaceName = 'North';
        $applicationContext->productCapabilities = 'Listings.';

        $liveInstructions = $applicationContext->composeLiveInstructions();

        static::assertStringEndsWith(
            AgentVoicePromptText::forName(AgentVoiceAIPromptNames::APP_AI_VOICE_DELEGATION_RULES) . "\n\nThe call is about the workspace North.",
            $liveInstructions
        );
        static::assertSameValueObjects(
            [
                AgentVoiceSeedInputMessage::create('developer', "The owner's product scope (context only, do not recite):\nListings."),
                AgentVoiceSeedInputMessage::create('developer', 'Workspace notes of North'),
            ],
            $applicationContext->buildLiveSeedInputMessages()
        );
        static::assertStringStartsWith('The owner opened this call from inside the application and is signed in (account 0).', $liveInstructions);
    }
}
