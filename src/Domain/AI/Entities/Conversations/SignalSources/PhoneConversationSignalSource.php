<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\SignalSources;

/**
 * The signal sources of a call: the stored
 * {@see \DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage::$signalSource} values the voice channel
 * writes (the services in `Domain/AI/Services/Voice/`). The enum lives in this folder, beside the other signal source
 * enums and outside the `Voice/` folders: {@see ConversationSignalSources} finds it here through the folder
 * convention. A case no class of the package writes ({@see self::PHONE_VOICEMAIL}) stays for an application whose own
 * medium writes it and whose tables hold it. The cases answer the basic questions, the derived ones (among them
 * {@see ConversationSignalSource::isPhoneCallLifecycle()}) and the core vocabulary come from
 * {@see ConversationSignalSourceDefaultAnswers}.
 */
enum PhoneConversationSignalSource: string implements ConversationSignalSource
{
    use ConversationSignalSourceDefaultAnswers;

    /**
     * A signal source of the voice channel: a spoken message somebody left for the owner on a medium of the
     * application, without a live call with the agent. For the conversation: the medium posts the transcript of the
     * message as this signal into a fresh conversation of the OWNER's account and starts a turn, so the agent brings
     * the message to the owner by itself. TURN-OWING. No class of the package writes this case: it is the case of an
     * application's own medium.
     */
    case PHONE_VOICEMAIL = 'PHONE_VOICEMAIL';

    /**
     * A signal source of the voice channel: a call on THIS conversation was ANSWERED. For the conversation: the chat
     * renders it as a call marker chip and the agent knows structurally that a call is running. NON-turn-owing: the
     * turn derivation sees through the lifecycle signals of a call
     * ({@see \DDD\Domain\AI\Entities\Conversations\AIConversation::hasUnansweredInboundMessage()}).
     */
    case PHONE_CALL_STARTED = 'PHONE_CALL_STARTED';

    /**
     * A signal source of the voice channel: a call on THIS conversation ENDED (the text carries the duration and the
     * end reason, including `not_answered` when the owner declined or never answered). For the conversation: a call
     * marker chip in the chat and structural context for the agent (a delegated task that finishes after the call has
     * ended knows that nobody is listening any more). NON-turn-owing like {@see self::PHONE_CALL_STARTED}.
     */
    case PHONE_CALL_ENDED = 'PHONE_CALL_ENDED';

    /**
     * A signal source of the voice channel: the voice model of a running call DELEGATED a request to the agent. For
     * the conversation: the request is the transcript itself (the USER rows the call persisted right before this
     * signal), so the signal carries no copy of it, only the instruction to act on it. TURN-OWING (deliberately not a
     * lifecycle signal): the trailing signal is the owed work, exactly like a signal-only start.
     */
    case PHONE_DELEGATION = 'PHONE_DELEGATION';

    /**
     * A signal source of the voice channel: a call the agent ITSELF started (`startVoiceCall`) ended unanswered: its
     * ring EXPIRED on every client of the owner, or its medium did not reach the owner. For the conversation: the
     * platform starts ONE turn in the conversation the agent started the call from, so the agent tells the owner that
     * the call was not answered and ASKS whether to try another channel; it never calls again by itself. Written once
     * per call by the voice channel's follow-up service (`AgentVoiceCallFollowUpsService`) behind the call's
     * unanswered-end claim; the text names the outcome and the channels that reach the owner now. TURN-OWING; a
     * running turn takes it over at its next model call instead of a second reaction. A DECLINED ring never writes it.
     */
    case PHONE_CALL_UNANSWERED_FOLLOW_UP = 'PHONE_CALL_UNANSWERED_FOLLOW_UP';

    // --- basic questions ---

    /** The call's start and end are the owner's own presence; the voicemail and the delegation are the platform's. */
    public function getSpeaker(): ConversationSignalSpeaker
    {
        return $this === self::PHONE_CALL_STARTED || $this === self::PHONE_CALL_ENDED ? ConversationSignalSpeaker::OWNER : ConversationSignalSpeaker::PLATFORM;
    }

    /** The call markers are chips the owed-turn walks see through; the voicemail, the delegation and the follow-up owe an answer. */
    public function owesWork(): bool
    {
        return $this !== self::PHONE_CALL_STARTED && $this !== self::PHONE_CALL_ENDED;
    }

    /** The unanswered-call follow-up reaches a running turn at its next model call — one reaction, never two. */
    public function isPickedUpByRunningTurn(): bool
    {
        return $this === self::PHONE_CALL_UNANSWERED_FOLLOW_UP;
    }

    /** An answered call is the owner being there (the metrics' owner origin). */
    public function marksOwnerPresence(): bool
    {
        return $this === self::PHONE_CALL_STARTED;
    }

    // --- presentation properties ---

    /** A conversation opened by an answered call counts as `phoneCall` in the scorecard (stored bytes). */
    public function getMetricsEntryPoint(): ?string
    {
        return $this === self::PHONE_CALL_STARTED ? 'phoneCall' : null;
    }

    /** The answered call's text is an instruction to the agent; the analysis shows only that the call started. */
    public function getAnalysisTranscriptLabel(): ?string
    {
        return $this === self::PHONE_CALL_STARTED ? 'PHONE CALL STARTED' : null;
    }
}
