<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\AI\Entities\Voice\Prompts\AgentVoiceAIPromptNames;
use DDD\Domain\AI\Entities\Voice\Prompts\AgentVoicePromptText;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the voice model of ONE call is told when its session is created: the instructions (who is on the line, how the
 * call opens, how the voice sounds, which language it speaks, when it hands a request to the agent) and the seed rows
 * of the session (the opening order, the agent's character, the owner's standing directives, what was discussed
 * before, what the owner's product can do).
 *
 * Composition only: no HTTP, no repository. {@see \DDD\Domain\AI\Services\Voice\AgentVoiceSessionContextsService}
 * fills the fields (it owns the data access), this object assembles the texts. It carries what a SPEAKING face needs
 * and nothing of the agent's loop (skills, tool schemas, fetched data): everything detailed or live stays behind the
 * agent, which the voice model reaches by delegation.
 *
 * Every sentence is a prompt of the package ({@see AgentVoiceAIPromptNames}), written in ENGLISH on purpose: a voice
 * model imitates the language of the text it is given. Which language is SPOKEN is stated once, by
 * {@see $languageInstruction}; no sentence here may pre-empt that.
 *
 * Override points of an application's subclass at the same path:
 * - further blocks of the instructions and further seed rows (what the application knows of the agent space, the rules
 *   of a further medium): {@see buildApplicationInstructionBlocks()} and {@see buildApplicationSeedInputMessages()};
 * - further placeholders of the opening block of its own prompt file: {@see getOpeningPromptParameters()};
 * - the prompt each sentence is read from: the `get…PromptName()` methods.
 */
class AgentVoiceSessionContext extends ValueObject
{
    /** @var int The account of the signed-in owner on this call, whose rights the agent acts with */
    public int $authenticatedAccountId = 0;

    /** @var string|null The owner's standing directives, rendered as one block by the agent's memory store */
    public ?string $memoryDirectivesBlock = null;

    /** @var string|null What was recently discussed with the owner, as the conversation carries it: makes the call continuous with earlier sessions */
    public ?string $episodicHistory = null;

    /** @var string|null What the owner's product can do, so that the voice frames requests correctly */
    public ?string $productCapabilities = null;

    /** @var string|null The agent's character, the same text the chat's base prompt embeds, with its persona names filled */
    public ?string $characterCore = null;

    /** @var string|null The task of a call the agent started; null on a call the owner started */
    public ?string $callTask = null;

    /** @var string|null How the owner is addressed (name and register), the same line the chat's base prompt embeds */
    public ?string $ownerAddressing = null;

    /**
     * @var string|null The language rule of the call ({@see \DDD\Domain\AI\Entities\Voice\AgentVoiceSettings::buildLanguageInstruction()}).
     * Null falls back to {@see buildLanguageInstructionWithoutSettings()}. The ONLY place in this briefing that decides
     * the spoken language.
     */
    public ?string $languageInstruction = null;

    /** @var string|null The call's language (ISO 639-1): the examples of the briefing follow it; null keeps every example */
    public ?string $callLanguageCode = null;

    /** @var string|null The speaking style the owner wrote, put verbatim into the instructions */
    public ?string $speakingStyle = null;

    /**
     * @var string WHO started the call ({@see AgentVoiceCall::$direction}). INBOUND: the owner opened the call, and the
     * opening confirms the connection. OUTBOUND: the agent rang the owner, so it greets, states the reason of the call
     * and drives toward the task. Address and goal differ fundamentally between the two.
     */
    public string $callDirection = AgentVoiceCall::DIRECTION_INBOUND;

    /**
     * @var bool The call has no conversation yet: nothing is known but who started it and which language is spoken.
     * The instructions are then the one short text of {@see getWithoutConversationPromptName()}.
     */
    public bool $isWithoutConversation = false;

    /**
     * The language rule of a call whose language nobody states. Names NO language on purpose: any fixed language here
     * would be a guess, and a guessed language is spoken to every caller. Following the caller is the only rule that
     * is right without knowing who they are.
     */
    public static function buildLanguageInstructionWithoutSettings(): string
    {
        return AgentVoicePromptText::forName(static::getLanguageOfTheCallerPromptName());
    }

    /**
     * HOW the voice sounds: labeled bullets, the CAPITALIZED variety rule, the timing of filler words, broken written
     * grammar, a calm baseline, natural clarification, the call language. English (the prompt language); the same
     * rules in every phase of a call, so that the voice never changes mid-call.
     */
    public static function buildSpokenDeliveryInstructions(?string $callLanguageCode = null): string
    {
        return AgentVoicePromptText::forName(static::getSpeechDeliveryPromptName())
            . "\n" . static::buildGroupWordsRule($callLanguageCode);
    }

    /**
     * The rule for words that name groups. With a known call language other than German the German examples are left
     * out: the voice model takes an example's language for its own. Null (no language known yet) keeps every example.
     */
    public static function buildGroupWordsRule(?string $callLanguageCode = null): string
    {
        $showsGermanExamples = $callLanguageCode === null || strtolower($callLanguageCode) === 'de';
        $germanExamples = $showsGermanExamples
            ? AgentVoicePromptText::forName(static::getGroupWordsGermanExamplesPromptName())
            : '';

        return AgentVoicePromptText::forName(
            static::getGroupWordsPromptName(),
            ['GERMAN_EXAMPLES' => $germanExamples !== '' ? $germanExamples . ' ' : '']
        );
    }

    /**
     * The instructions the session is created with. Deliberately SHORT: a live voice model gets a role, a style and
     * the rules for when to involve the backend, and keeps detailed procedures in the backend's prompt. It holds no
     * tools, delegates to the agent, and has a small working context. So this carries the opening, the task, the
     * address, the speech delivery, the language rule and the delegation rules ONLY; the briefing blocks (character,
     * standing directives, what was discussed, product scope) travel as seed rows instead
     * ({@see buildLiveSeedInputMessages()}).
     */
    public function composeLiveInstructions(): string
    {
        if ($this->isWithoutConversation) {
            return AgentVoicePromptText::forName(static::getWithoutConversationPromptName());
        }
        $liveInstructionBlocks = [
            $this->buildOpeningInstructionBlock(),
            // The TASK is in the instructions, not the seed: the model opens the call itself, and the opening must
            // already state the reason of the call.
            $this->buildLiveCallTaskBlock(),
            ($this->ownerAddressing !== null && trim($this->ownerAddressing) !== ''
                ? trim($this->ownerAddressing)
                : null),
            static::buildSpokenDeliveryInstructions($this->callLanguageCode)
            . "\n" . $this->buildLanguageAndSpeakingStyleInstruction(),
            $this->buildLiveDelegationRules(),
            ...$this->buildApplicationInstructionBlocks(),
        ];

        return implode("\n\n", array_filter(
            $liveInstructionBlocks,
            static fn (?string $liveInstructionBlock): bool => $liveInstructionBlock !== null && $liveInstructionBlock !== ''
        ));
    }

    /** The task of a call the agent started as an instruction block; null when the call carries no task. */
    public function buildLiveCallTaskBlock(): ?string
    {
        if ($this->callTask === null || trim($this->callTask) === '') {
            return null;
        }

        return AgentVoicePromptText::forName(static::getCallTaskPromptName(), ['CALL_TASK' => trim($this->callTask)]);
    }

    /**
     * The delegation rules of the session: the model's ONLY way to reach the backend, so this block is the whole
     * contract of when to hand over and how to behave meanwhile.
     */
    public function buildLiveDelegationRules(): string
    {
        return AgentVoicePromptText::forName(static::getDelegationRulesPromptName());
    }

    /**
     * The opening order as a seed row: the place a live voice provider names for greeting behaviour (a `developer`
     * row of the session's input at creation: language, greeting, speak first, then listen). An order in the
     * instructions alone does not make the model speak first, and an instruction appended later only takes effect
     * once the session clock, which runs on input audio, reaches it.
     */
    public function buildLiveOpeningSeedInputMessage(): AgentVoiceSeedInputMessage
    {
        return AgentVoiceSeedInputMessage::create(
            AgentVoiceSeedInputMessage::ROLE_DEVELOPER,
            AgentVoicePromptText::forName(
                $this->callDirection === AgentVoiceCall::DIRECTION_OUTBOUND
                    ? static::getOpeningSeedOfAgentStartedCallPromptName()
                    : static::getOpeningSeedPromptName(),
                ['LANGUAGE_INSTRUCTION' => $this->languageInstruction ?? static::buildLanguageInstructionWithoutSettings()]
            )
        );
    }

    /**
     * The whole seed of a session: the opening row FIRST, then the briefing blocks. The adapter fills the seed in this
     * order up to its size limit, so the row that must never be cut goes first.
     */
    public function buildLiveCreationSeedInputMessages(): AgentVoiceSeedInputMessages
    {
        return AgentVoiceSeedInputMessages::fromMessages(
            $this->buildLiveOpeningSeedInputMessage(),
            ...$this->buildLiveSeedInputMessages()->getElements()
        );
    }

    /**
     * The briefing blocks as SEED rows of the session (`developer` rows): the agent's character, the owner's standing
     * directives, what was last discussed and the product scope, then the application's rows (the call task is in the
     * instructions, {@see buildLiveCallTaskBlock()}). They sit where a live model keeps context instead of rules. Empty
     * blocks are skipped; the adapter caps count and size.
     */
    public function buildLiveSeedInputMessages(): AgentVoiceSeedInputMessages
    {
        $seedInputMessages = [];
        if ($this->characterCore !== null && trim($this->characterCore) !== '') {
            $seedInputMessages[] = AgentVoiceSeedInputMessage::create(
                AgentVoiceSeedInputMessage::ROLE_DEVELOPER,
                AgentVoicePromptText::forName(static::getCharacterSeedPromptName(), ['CHARACTER' => trim($this->characterCore)])
            );
        }
        if ($this->memoryDirectivesBlock !== null && trim($this->memoryDirectivesBlock) !== '') {
            $seedInputMessages[] = AgentVoiceSeedInputMessage::create(
                AgentVoiceSeedInputMessage::ROLE_DEVELOPER,
                AgentVoicePromptText::forName(static::getStandingRulesSeedPromptName(), ['STANDING_RULES' => trim($this->memoryDirectivesBlock)])
            );
        }
        if ($this->episodicHistory !== null && trim($this->episodicHistory) !== '') {
            $seedInputMessages[] = AgentVoiceSeedInputMessage::create(
                AgentVoiceSeedInputMessage::ROLE_DEVELOPER,
                AgentVoicePromptText::forName(static::getLastDiscussedSeedPromptName(), ['LAST_DISCUSSED' => trim($this->episodicHistory)])
            );
        }
        if ($this->productCapabilities !== null && trim($this->productCapabilities) !== '') {
            $seedInputMessages[] = AgentVoiceSeedInputMessage::create(
                AgentVoiceSeedInputMessage::ROLE_DEVELOPER,
                AgentVoicePromptText::forName(static::getProductScopeSeedPromptName(), ['PRODUCT_SCOPE' => trim($this->productCapabilities)])
            );
        }

        return AgentVoiceSeedInputMessages::fromMessages(
            ...$seedInputMessages,
            ...$this->buildApplicationSeedInputMessages()->getElements()
        );
    }

    /** The opening block: who is on the line and how the call opens, by who started the call. */
    protected function buildOpeningInstructionBlock(): string
    {
        return AgentVoicePromptText::forName(
            $this->callDirection === AgentVoiceCall::DIRECTION_OUTBOUND
                ? static::getOpeningOfAgentStartedCallPromptName()
                : static::getOpeningPromptName(),
            $this->getOpeningPromptParameters()
        );
    }

    /** The language rule of the call, followed by the owner's speaking style when one is set. */
    protected function buildLanguageAndSpeakingStyleInstruction(): string
    {
        $languageInstruction = $this->languageInstruction ?? static::buildLanguageInstructionWithoutSettings();
        if ($this->speakingStyle === null || trim($this->speakingStyle) === '') {
            return $languageInstruction;
        }

        return $languageInstruction . ' ' . AgentVoicePromptText::forName(
            static::getSpeakingStylePromptName(),
            ['SPEAKING_STYLE' => trim($this->speakingStyle)]
        );
    }

    /**
     * The placeholders of the opening block. Override point: an application whose own prompt file names more (the
     * name of the agent space the call is about) adds them here.
     *
     * @return array<string, string|int|float>
     */
    protected function getOpeningPromptParameters(): array
    {
        return ['ACCOUNT_ID' => $this->authenticatedAccountId];
    }

    /**
     * Override point: further blocks of the instructions, after the delegation rules. Empty in the base.
     *
     * @return string[]
     */
    protected function buildApplicationInstructionBlocks(): array
    {
        return [];
    }

    /**
     * Override point: further seed rows, after the product scope. Empty in the base.
     */
    protected function buildApplicationSeedInputMessages(): AgentVoiceSeedInputMessages
    {
        return new AgentVoiceSeedInputMessages();
    }

    /** The prompt of the opening block of a call the owner started. */
    protected static function getOpeningPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_BRIEFING;
    }

    /** The prompt of the opening block of a call the agent started. */
    protected static function getOpeningOfAgentStartedCallPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_BRIEFING_AGENT_STARTED_CALL;
    }

    /** The prompt of the whole instructions of a call without a conversation. */
    protected static function getWithoutConversationPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_BRIEFING_WITHOUT_CONVERSATION;
    }

    /** The prompt of the task block. */
    protected static function getCallTaskPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_CALL_TASK;
    }

    /** The prompt of the speech delivery rules. */
    protected static function getSpeechDeliveryPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_SPEECH_DELIVERY;
    }

    /** The prompt of the rule for words that name groups. */
    protected static function getGroupWordsPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_GROUP_WORDS;
    }

    /** The prompt of the German examples of the group words rule. */
    protected static function getGroupWordsGermanExamplesPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_GROUP_WORDS_GERMAN_EXAMPLES;
    }

    /** The prompt of the language rule of a call whose language nobody states. */
    protected static function getLanguageOfTheCallerPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_LANGUAGE_OF_THE_CALLER;
    }

    /** The prompt of the sentence that carries the owner's speaking style. */
    protected static function getSpeakingStylePromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_SPEAKING_STYLE;
    }

    /** The prompt of the delegation rules. */
    protected static function getDelegationRulesPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_DELEGATION_RULES;
    }

    /** The prompt of the opening seed row of a call the owner started. */
    protected static function getOpeningSeedPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_OPENING_SEED;
    }

    /** The prompt of the opening seed row of a call the agent started. */
    protected static function getOpeningSeedOfAgentStartedCallPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_OPENING_SEED_AGENT_STARTED_CALL;
    }

    /** The prompt of the seed row with the agent's character. */
    protected static function getCharacterSeedPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_SEED_CHARACTER;
    }

    /** The prompt of the seed row with the owner's standing directives. */
    protected static function getStandingRulesSeedPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_SEED_STANDING_RULES;
    }

    /** The prompt of the seed row with what was discussed before. */
    protected static function getLastDiscussedSeedPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_SEED_LAST_DISCUSSED;
    }

    /** The prompt of the seed row with the product scope. */
    protected static function getProductScopeSeedPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_SEED_PRODUCT_SCOPE;
    }
}
