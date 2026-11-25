<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Entities\Voice\AgentVoiceSettings;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallRefusal;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSessionContext;
use DDD\Domain\AI\Entities\Voice\Prompts\AgentVoiceAIPromptNames;
use DDD\Domain\AI\Entities\Voice\Prompts\AgentVoicePromptText;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\AIPromptsService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Throwable;

/**
 * What the voice model of a call is told: the briefing its session is created with
 * ({@see buildVoiceSessionContext()}), the language the call is held in ({@see resolveCallLanguageCode()}), and the
 * notes the session worker sends while the call runs (the silence check, the repeated question, the goodbye, the end
 * of the allowance), each closed by the clause that keeps the call's language.
 *
 * The briefing is built from what the agent itself reads for the chat of the same conversation (the character, the
 * owner's address, the standing directives of the agent's memory store, what was discussed before, the product
 * awareness), so that voice and chat never say different things. Stored state only: no call to another system stands
 * between the owner's click and the first spoken sentence.
 *
 * Override points of an application's subclass at the same path:
 * - the voice settings of a call, e.g. stored per line or per owner: {@see findEffectiveVoiceSettingsOfCall()};
 * - what the application adds to the briefing from what it knows of the agent space the call is about:
 *   {@see applyApplicationAdditions()} (the fields of its own subclass of the context);
 * - a source of its own for one block: {@see findCallTask()}, {@see findCharacterCoreOfConversation()},
 *   {@see findMemoryDirectivesBlockOfConversation()}, {@see findProductCapabilitiesOfConversation()};
 * - the prompts of the notes sent while the call runs: {@see getSilenceCheckPromptName()},
 *   {@see getQuestionRepeatPromptName()}, {@see getGoodbyePromptName()},
 *   {@see getGoodbyeAfterSilenceChecksPromptName()}, {@see getBudgetEndPromptName()},
 *   {@see getKeptLanguagePromptName()}.
 */
class AgentVoiceSessionContextsService extends Service
{
    /**
     * The briefing of a call on its conversation. Best-effort per block: a failed source shrinks the briefing, it
     * never breaks the call.
     *
     * @param AIConversation $conversation
     * @param AgentVoiceCall $agentVoiceCall
     * @return AgentVoiceSessionContext
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function buildVoiceSessionContext(AIConversation $conversation, AgentVoiceCall $agentVoiceCall): AgentVoiceSessionContext
    {
        $voiceSessionContext = AgentVoiceSessionContext::newInstance();
        $voiceSessionContext->authenticatedAccountId = (int)($conversation->accountId ?? 0);
        $voiceSessionContext->callDirection = $agentVoiceCall->direction;
        $voiceSessionContext->episodicHistory = isset($conversation->episodicHistory) ? $conversation->episodicHistory : null;
        $voiceSessionContext->callTask = $this->findCallTask($conversation, $agentVoiceCall);

        // The SHARED character: the same prompt the chat's base prompt embeds, so that the agent of the chat and the
        // agent of the call cannot diverge in personality.
        try {
            $voiceSessionContext->characterCore = $this->findCharacterCoreOfConversation($conversation);
        } catch (Throwable) {
            // Best-effort: a missing character file shrinks the briefing, never breaks the call.
        }

        // The voice settings of the call carry the language rule and the speaking style of the instructions.
        $effectiveVoiceSettings = null;
        try {
            $effectiveVoiceSettings = $this->findEffectiveVoiceSettingsOfCall($agentVoiceCall, $conversation);
        } catch (Throwable) {
            // Best-effort: without settings the briefing follows the caller's language.
        }
        if ($effectiveVoiceSettings !== null) {
            $voiceSessionContext->languageInstruction = $effectiveVoiceSettings->buildLanguageInstruction();
            $voiceSessionContext->speakingStyle = $effectiveVoiceSettings->speakingStyle;
        }
        try {
            // the same source as the notes sent while the call runs
            $voiceSessionContext->callLanguageCode = $this->resolveCallLanguageCode($conversation, $effectiveVoiceSettings);
        } catch (Throwable) {
            // without a language the briefing keeps every example
        }

        // The owner's address: the same line the chat's base prompt embeds, so that both channels address the owner
        // identically.
        try {
            $voiceSessionContext->ownerAddressing = $this->getAgentSpacesService()
                ->buildOwnerAddressingInstruction($conversation, $voiceSessionContext->callLanguageCode);
        } catch (Throwable) {
            // Best-effort: the briefing simply lacks the addressing line.
        }

        // The standing directives: the same store and rendering as the chat's model input.
        try {
            $voiceSessionContext->memoryDirectivesBlock = $this->findMemoryDirectivesBlockOfConversation($conversation);
        } catch (Throwable) {
            // Best-effort: the briefing simply lacks the standing directives.
        }

        // What the owner's product can do, as the agent's own model input states it.
        try {
            $voiceSessionContext->productCapabilities = $this->findProductCapabilitiesOfConversation($conversation);
        } catch (Throwable) {
            // Best-effort: the call proceeds with a briefing that simply lacks the product frame.
        }

        try {
            $this->applyApplicationAdditions($voiceSessionContext, $conversation, $agentVoiceCall);
        } catch (Throwable) {
            // Best-effort: the briefing simply lacks the application's additions.
        }

        return $voiceSessionContext;
    }

    /**
     * The briefing of a call that has no conversation yet: one short instruction text and the opening row. The model
     * speaks before it hears anyone, so the language of the call's voice settings decides the opening.
     */
    public function buildVoiceSessionContextWithoutConversation(AgentVoiceCall $agentVoiceCall): AgentVoiceSessionContext
    {
        $voiceSessionContext = AgentVoiceSessionContext::newInstance();
        $voiceSessionContext->isWithoutConversation = true;
        $voiceSessionContext->authenticatedAccountId = (int)($agentVoiceCall->accountId ?? 0);
        $voiceSessionContext->callDirection = $agentVoiceCall->direction;
        try {
            $effectiveVoiceSettings = $this->findEffectiveVoiceSettingsOfCall($agentVoiceCall);
            $voiceSessionContext->languageInstruction = $effectiveVoiceSettings?->buildLanguageInstruction();
            $voiceSessionContext->callLanguageCode = $effectiveVoiceSettings?->languageCode;
        } catch (Throwable) {
            // Best-effort: without settings the opening follows the caller's language.
        }

        return $voiceSessionContext;
    }

    /**
     * The voice settings a call speaks with. The base knows the configured default only: the configured language of
     * the voice channel, else the language of the call's conversation, following the caller from there; no stored
     * voice, so the adapter speaks with its default voice. Override point: an application that stores voice settings
     * (per line, per owner, per agent space) merges its layers onto the default here.
     *
     * @param AgentVoiceCall $agentVoiceCall
     * @param AIConversation|null $conversation The call's conversation when the caller already holds it
     * @return AgentVoiceSettings|null
     */
    public function findEffectiveVoiceSettingsOfCall(AgentVoiceCall $agentVoiceCall, ?AIConversation $conversation = null): ?AgentVoiceSettings
    {
        $spokenLanguageCode = null;
        try {
            $spokenLanguageCode = $conversation?->resolveUserLanguageCode();
        } catch (Throwable) {
            // the default language of the application decides
        }

        return AgentVoiceSettings::buildConfiguredDefault(
            $spokenLanguageCode !== null && trim($spokenLanguageCode) !== '' ? $spokenLanguageCode : null
        );
    }

    /**
     * The language a voice call is held in, as a two-letter code: settings pinned to one language
     * (`mirrorCallerLanguage` false) speak that; else the newest applied reply-language decision of the conversation;
     * else the language of the settings; else, with `$withConfiguredFallback`, the configured chain of the
     * conversation. Null when nothing names it.
     *
     * The decision is read from the working state as it is, NOT through the conversation's
     * `getAppliedReplyLanguageDecision()`: that read answers only for the newest owner message, and in a call every
     * spoken sentence of the owner is a newer one, so the language a delegated turn decided would be dropped with the
     * owner's next sentence. The price: a call that continues an older conversation starts in the language that
     * conversation was last answered in, until a turn of the call decides anew.
     *
     * @param AIConversation $conversation
     * @param AgentVoiceSettings|null $effectiveVoiceSettings
     * @param bool $withConfiguredFallback
     * @return string|null
     */
    public function resolveCallLanguageCode(
        AIConversation $conversation,
        ?AgentVoiceSettings $effectiveVoiceSettings = null,
        bool $withConfiguredFallback = true
    ): ?string {
        if ($effectiveVoiceSettings !== null && $effectiveVoiceSettings->mirrorCallerLanguage === false) {
            $callLanguageCode = $effectiveVoiceSettings->getLanguageCodeOrDefault();
        } else {
            $replyLanguageDecision = $conversation->getAgentWorkingState()?->replyLanguageDecision;
            $callLanguageCode = ($replyLanguageDecision !== null && $replyLanguageDecision->isApplied() ? $replyLanguageDecision->languageCode : null)
                ?? $effectiveVoiceSettings?->getLanguageCodeOrDefault()
                ?? ($withConfiguredFallback ? $conversation->resolveUserLanguageCode() : null);
        }
        if ($callLanguageCode === null || trim($callLanguageCode) === '') {
            return null;
        }
        return strtolower(explode('-', str_replace('_', '-', $callLanguageCode))[0]);
    }

    /**
     * The language of the call as stored NOW (the conversation read uncached): a session worker that holds the
     * conversation since the call began reads the language a later delegated turn decided; the call's voice settings
     * fix or default it. Null without the attached conversation.
     *
     * @param AgentVoiceCall $agentVoiceCall
     * @return string|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCallLanguageCodeOfVoiceCall(AgentVoiceCall $agentVoiceCall): ?string
    {
        if ($agentVoiceCall->aiConversationId === null) {
            return null;
        }
        $conversation = AIConversations::getService()->find((int)$agentVoiceCall->aiConversationId, false);
        if (!$conversation instanceof AIConversation) {
            return null;
        }
        return $this->resolveCallLanguageCode(
            $conversation,
            $this->findEffectiveVoiceSettingsOfCall($agentVoiceCall, $conversation),
            withConfiguredFallback: false
        );
    }

    /**
     * The closing clause of every note sent while a call runs: the call's language NAMED
     * ({@see AgentVoiceSettings::buildMidCallLanguageClause()}), read fresh, so that a delegated turn after which the
     * caller switched is honoured. Without a readable language the clause describes it as the language the call has
     * been held in: the notes are English, and "in the call language" alone lets a live model follow the note's own
     * language. Starts with a space: it is appended to the note's last sentence.
     */
    public function buildCallLanguageClause(AgentVoiceCall $agentVoiceCall): string
    {
        $callLanguageCode = null;
        try {
            $callLanguageCode = $this->findCallLanguageCodeOfVoiceCall($agentVoiceCall);
        } catch (Throwable) {
            // the described clause below
        }
        if (($callLanguageCode ?? '') !== '') {
            return AgentVoiceSettings::buildMidCallLanguageClause($callLanguageCode);
        }
        $keptLanguageClause = AgentVoicePromptText::forName($this->getKeptLanguagePromptName());

        return $keptLanguageClause !== '' ? ' ' . $keptLanguageClause : '';
    }

    /** The note after nobody spoke for a while: the model asks once whether the caller is still there. */
    public function buildSilenceCheckInstruction(AgentVoiceCall $agentVoiceCall): string
    {
        return AgentVoicePromptText::forName($this->getSilenceCheckPromptName()) . $this->buildCallLanguageClause($agentVoiceCall);
    }

    /** The note after the caller left the agent's last question unanswered: the model asks it once more. */
    public function buildQuestionRepeatInstruction(AgentVoiceCall $agentVoiceCall): string
    {
        return AgentVoicePromptText::forName($this->getQuestionRepeatPromptName()) . $this->buildCallLanguageClause($agentVoiceCall);
    }

    /**
     * The note before a silent call is hung up: one short goodbye.
     *
     * @param AgentVoiceCall $agentVoiceCall
     * @param bool $afterSilenceChecks True when the silence checks stayed unanswered, false when nobody spoke for the
     *     whole hang-up time
     * @return string
     */
    public function buildGoodbyeInstruction(AgentVoiceCall $agentVoiceCall, bool $afterSilenceChecks = false): string
    {
        return AgentVoicePromptText::forName($afterSilenceChecks ? $this->getGoodbyeAfterSilenceChecksPromptName() : $this->getGoodbyePromptName())
            . $this->buildCallLanguageClause($agentVoiceCall);
    }

    /** The note before a call ends because the account's allowance ran out while it was running. */
    public function buildBudgetEndInstruction(AgentVoiceCall $agentVoiceCall): string
    {
        return AgentVoicePromptText::forName($this->getBudgetEndPromptName()) . $this->buildCallLanguageClause($agentVoiceCall);
    }

    /**
     * The note before a running call that was refused is hung up. A refusal about money or time running out
     * ({@see AgentVoiceCallRefusal::isLimitRefusal()}) is told as such ({@see buildBudgetEndInstruction()}); any other
     * refusal ends the call with one sentence that names no reason, so the voice never claims a used-up allowance
     * that is none. An application that words its own refusal codes overrides this method.
     */
    public function buildRefusedCallGoodbyeInstruction(AgentVoiceCall $agentVoiceCall, AgentVoiceCallRefusal $agentVoiceCallRefusal): string
    {
        if ($agentVoiceCallRefusal->isLimitRefusal()) {
            return $this->buildBudgetEndInstruction($agentVoiceCall);
        }

        return AgentVoicePromptText::forName($this->getRefusedCallGoodbyePromptName()) . $this->buildCallLanguageClause($agentVoiceCall);
    }

    /**
     * The task of a call: the call's own task when set (a call carried by an EXISTING conversation has no seeded task
     * message), else the conversation's FIRST message of the owner's role (a conversation started for the call is
     * seeded with its task). A call with neither has no task block.
     */
    protected function findCallTask(AIConversation $conversation, AgentVoiceCall $agentVoiceCall): ?string
    {
        if (($agentVoiceCall->callTask ?? null) !== null && trim((string)$agentVoiceCall->callTask) !== '') {
            return trim((string)$agentVoiceCall->callTask);
        }
        foreach ($conversation->messages?->getRowsSortedByIndex() ?? [] as $conversationMessage) {
            if ($conversationMessage->isUserRoleText()) {
                return $conversationMessage->message;
            }
        }

        return null;
    }

    /**
     * The agent's character with its persona names filled: the product name of the conversation
     * ({@see AgentSpacesService::findProductNameOfConversation()}), else the persona's.
     */
    protected function findCharacterCoreOfConversation(AIConversation $conversation): ?string
    {
        $characterPrompt = $this->getAIPromptsService()->getAIPromptByName(AgentAIPromptNames::APP_AI_AGENT_CHARACTER);
        if ($characterPrompt === null) {
            return null;
        }
        $agentPersonaNames = ArgusAIAgentConversation::findAgentPersonaNames();
        $customProductName = $this->getAgentSpacesService()->findProductNameOfConversation($conversation);

        return ArgusAIAgentConversation::buildAgentCharacterText(
            $characterPrompt->promptText,
            ($customProductName !== null && $customProductName !== '') ? $customProductName : $agentPersonaNames->productName,
            $agentPersonaNames->agentName
        );
    }

    /**
     * The owner's standing directives of the conversation's account and agent space, rendered by the agent's memory
     * store. Null without an account, while the memory store is switched off, or without a directive.
     */
    protected function findMemoryDirectivesBlockOfConversation(AIConversation $conversation): ?string
    {
        if (!(isset($conversation->accountId) && $conversation->accountId) || !AgentMemoriesService::isMemoryStoreEnabled()) {
            return null;
        }
        $directivesBlock = $this->getAgentMemoryStoreService()
            ->findCurrentDirectivesForScope((int)$conversation->accountId, $conversation->agentSpaceId ?? null)
            ->toDirectivesBlock();

        return trim($directivesBlock) !== '' ? $directivesBlock : null;
    }

    /** What the owner's product can do: the product capabilities of the agent's own product awareness. */
    protected function findProductCapabilitiesOfConversation(AIConversation $conversation): ?string
    {
        return $conversation->findProductAwareness()->productCapabilities;
    }

    /**
     * Override point: what the application adds to the briefing from what it knows of the agent space the call is
     * about. An application fills the fields of its own subclass of the context here, which that subclass renders
     * ({@see AgentVoiceSessionContext::buildApplicationInstructionBlocks()},
     * {@see AgentVoiceSessionContext::buildApplicationSeedInputMessages()}). Nothing in the base.
     */
    protected function applyApplicationAdditions(
        AgentVoiceSessionContext $voiceSessionContext,
        AIConversation $conversation,
        AgentVoiceCall $agentVoiceCall
    ): void {
    }

    /** The prompt of the silence check. */
    protected function getSilenceCheckPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_SILENCE_CHECK;
    }

    /** The prompt of the repeated question. */
    protected function getQuestionRepeatPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_QUESTION_REPEAT;
    }

    /** The prompt of the goodbye after nobody spoke for the whole hang-up time. */
    protected function getGoodbyePromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_GOODBYE;
    }

    /** The prompt of the goodbye after the silence checks stayed unanswered. */
    protected function getGoodbyeAfterSilenceChecksPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_GOODBYE_AFTER_SILENCE_CHECKS;
    }

    /** The prompt of the note that ends a call whose allowance ran out. */
    protected function getBudgetEndPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_BUDGET_END;
    }

    /** The prompt of the note that ends a refused call without naming a reason. */
    protected function getRefusedCallGoodbyePromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_REFUSED_CALL_GOODBYE;
    }

    /** The prompt of the language clause of a call whose language cannot be read. */
    protected function getKeptLanguagePromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_LANGUAGE_KEPT;
    }

    /**
     * @return AgentSpacesService
     */
    protected function getAgentSpacesService(): AgentSpacesService
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        return $agentSpacesService;
    }

    /**
     * @return AgentMemoryStoreService
     */
    protected function getAgentMemoryStoreService(): AgentMemoryStoreService
    {
        /** @var AgentMemoryStoreService $agentMemoryStoreService */
        $agentMemoryStoreService = DDDService::instance()->getService(AgentMemoryStoreService::class);
        return $agentMemoryStoreService;
    }

    /**
     * @return AIPromptsService
     */
    protected function getAIPromptsService(): AIPromptsService
    {
        /** @var AIPromptsService $aIPromptsService */
        $aIPromptsService = AIPrompt::getService();
        return $aIPromptsService;
    }
}
