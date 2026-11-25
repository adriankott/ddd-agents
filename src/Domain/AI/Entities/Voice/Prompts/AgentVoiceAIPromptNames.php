<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Prompts;

/**
 * The prompt names (config paths under `AI.Prompts.`) of the voice channel: every sentence the voice model is told.
 * The package ships a default file for each under its `config/app/AI/Prompts/AI/Voice/`, in English and without a
 * product name; an application's file at the same path replaces it whole. Every reader keeps the name it reads behind
 * a `protected` method, so an application's subclass can also read a prompt of its own.
 *
 * Every text is filled by {@see AgentVoicePromptText::forName()}; `{%AGENT_NAME%}` is available in each of them.
 */
class AgentVoiceAIPromptNames
{
    /**
     * @var string The first block of the instructions of a call the owner started: who is on the line, who the agent
     * is, how the call opens. Placeholder: ACCOUNT_ID.
     */
    public const string APP_AI_VOICE_BRIEFING = 'AI.Voice.Briefing';

    /** @var string The first block of the instructions of a call the agent started. Placeholder: ACCOUNT_ID. */
    public const string APP_AI_VOICE_BRIEFING_AGENT_STARTED_CALL = 'AI.Voice.BriefingAgentStartedCall';

    /** @var string The whole instructions of a call that has no conversation yet. */
    public const string APP_AI_VOICE_BRIEFING_WITHOUT_CONVERSATION = 'AI.Voice.BriefingWithoutConversation';

    /** @var string The task of a call the agent started, as a block of the instructions. Placeholder: CALL_TASK. */
    public const string APP_AI_VOICE_CALL_TASK = 'AI.Voice.CallTask';

    /** @var string How the voice sounds: the speech delivery rules of every call. */
    public const string APP_AI_VOICE_SPEECH_DELIVERY = 'AI.Voice.SpeechDelivery';

    /** @var string The rule for words that name groups, the last line of the speech delivery. Placeholder: GERMAN_EXAMPLES. */
    public const string APP_AI_VOICE_GROUP_WORDS = 'AI.Voice.GroupWords';

    /** @var string The German examples of the group words rule; left out of a call in another language. */
    public const string APP_AI_VOICE_GROUP_WORDS_GERMAN_EXAMPLES = 'AI.Voice.GroupWordsGermanExamples';

    /** @var string The language rule of a call held in one language whatever the caller speaks. Placeholder: LANGUAGE_NAME. */
    public const string APP_AI_VOICE_LANGUAGE_FIXED = 'AI.Voice.LanguageFixed';

    /** @var string The language rule of a call that opens in one language and then follows the caller. Placeholder: LANGUAGE_NAME. */
    public const string APP_AI_VOICE_LANGUAGE_MIRRORED = 'AI.Voice.LanguageMirrored';

    /** @var string The language rule of a call whose language is not known. */
    public const string APP_AI_VOICE_LANGUAGE_OF_THE_CALLER = 'AI.Voice.LanguageOfTheCaller';

    /** @var string The closing clause of a note sent while a call runs, naming the call's language. Placeholder: LANGUAGE_NAME. */
    public const string APP_AI_VOICE_LANGUAGE_MID_CALL = 'AI.Voice.LanguageMidCall';

    /** @var string The same clause for a call held in English. Placeholder: LANGUAGE_NAME. */
    public const string APP_AI_VOICE_LANGUAGE_MID_CALL_ENGLISH = 'AI.Voice.LanguageMidCallEnglish';

    /** @var string The closing clause of a note sent while a call runs whose language cannot be read. */
    public const string APP_AI_VOICE_LANGUAGE_KEPT = 'AI.Voice.LanguageKept';

    /** @var string The owner's speaking style, appended to the language rule. Placeholder: SPEAKING_STYLE. */
    public const string APP_AI_VOICE_SPEAKING_STYLE = 'AI.Voice.SpeakingStyle';

    /** @var string When the voice model hands a request to the agent and how it behaves meanwhile. */
    public const string APP_AI_VOICE_DELEGATION_RULES = 'AI.Voice.DelegationRules';

    /** @var string The first seed row of a call the owner started: the model speaks first. Placeholder: LANGUAGE_INSTRUCTION. */
    public const string APP_AI_VOICE_OPENING_SEED = 'AI.Voice.OpeningSeed';

    /** @var string The first seed row of a call the agent started. Placeholder: LANGUAGE_INSTRUCTION. */
    public const string APP_AI_VOICE_OPENING_SEED_AGENT_STARTED_CALL = 'AI.Voice.OpeningSeedAgentStartedCall';

    /** @var string The seed row with the agent's character. Placeholder: CHARACTER. */
    public const string APP_AI_VOICE_SEED_CHARACTER = 'AI.Voice.SeedCharacter';

    /** @var string The seed row with the owner's standing directives. Placeholder: STANDING_RULES. */
    public const string APP_AI_VOICE_SEED_STANDING_RULES = 'AI.Voice.SeedStandingRules';

    /** @var string The seed row with what was discussed before. Placeholder: LAST_DISCUSSED. */
    public const string APP_AI_VOICE_SEED_LAST_DISCUSSED = 'AI.Voice.SeedLastDiscussed';

    /** @var string The seed row with what the owner's product can do. Placeholder: PRODUCT_SCOPE. */
    public const string APP_AI_VOICE_SEED_PRODUCT_SCOPE = 'AI.Voice.SeedProductScope';

    /** @var string Nobody spoke for a while: the model asks once whether the caller is still there. */
    public const string APP_AI_VOICE_SILENCE_CHECK = 'AI.Voice.SilenceCheck';

    /** @var string The caller left the agent's last question unanswered: the model asks it once more. */
    public const string APP_AI_VOICE_QUESTION_REPEAT = 'AI.Voice.QuestionRepeat';

    /** @var string Nobody spoke for a long time: a short goodbye before the call is hung up. */
    public const string APP_AI_VOICE_GOODBYE = 'AI.Voice.Goodbye';

    /** @var string Nobody answered the silence checks: a short goodbye before the call is hung up. */
    public const string APP_AI_VOICE_GOODBYE_AFTER_SILENCE_CHECKS = 'AI.Voice.GoodbyeAfterSilenceChecks';

    /** @var string The call may not go on because the account's allowance ran out: one sentence, then the call ends. */
    public const string APP_AI_VOICE_BUDGET_END = 'AI.Voice.BudgetEnd';

    /** @var string The call may not go on for a reason that is no used-up allowance: one sentence without a reason, then the call ends. */
    public const string APP_AI_VOICE_REFUSED_CALL_GOODBYE = 'AI.Voice.RefusedCallGoodbye';

    /** @var string What the voice says before a call ends because its worker stops (a deploy): one goodbye, no reason. */
    public const string APP_AI_VOICE_WORKER_STOPPING_GOODBYE = 'AI.Voice.WorkerStoppingGoodbye';

    /** @var string What the voice says for an answer whose content is in widgets only. Placeholder: LEAD_IN. */
    public const string APP_AI_VOICE_ANSWER_SHOWN_IN_THE_CHAT = 'AI.Voice.AnswerShownInTheChat';

    /** @var string What an answer that was cut to the provider's size limit ends with. */
    public const string APP_AI_VOICE_ANSWER_CUT = 'AI.Voice.AnswerCut';

    /** @var string A running step of the agent that has no activity line yet, as unspoken context. Placeholder: STEP_WORDS. */
    public const string APP_AI_VOICE_STEP_WITHOUT_LINE = 'AI.Voice.StepWithoutLine';
}
