<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCall;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;

/**
 * ONE voice call between the owner and the agent, from its first moment (the owner opened a session, or the agent rang
 * the owner's open clients) until it hung up.
 *
 * A call and a conversation are two lifecycles: the call runs ON a conversation ({@see $aiConversationId}) whose
 * transcript fills while the call runs. The agent space of a call is the space of its conversation; the call carries no
 * space of its own, and a call is read under the read rights of its conversation.
 *
 * The base knows one medium, the application's own window ({@see self::CHANNEL_TYPE_APP}). An application with further
 * media adds its columns in a subclass at the same path and answers {@see getChannelType()} from them.
 *
 * Where a call is kept is decided by `AI.agentVoice.callStore`
 * ({@see \DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService}): as a row of this entity's table (`entity`, the
 * store the package ships), or in a store the application maps to a name of its own. The table is required only for
 * the first ({@see \DDD\Domain\AI\Services\Voice\Installation\AgentVoiceFeatureTableConditionProviderService}).
 *
 * @property AgentVoiceCalls $parent
 * @method AgentVoiceCalls getParent()
 * @method static AgentVoiceCallsService getService()
 */
#[QueryOptions]
// A provider may announce the same session more than once; its id is the idempotency key of the row.
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['providerCallId'])]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentVoiceCall::class)]
class AgentVoiceCall extends Entity implements LlmMarkdownRenderable
{
    use ChangeHistoryTrait;
    use QueryOptionsTrait;

    /** @var string The owner called the agent */
    public const string DIRECTION_INBOUND = 'INBOUND';

    /** @var string The agent called the owner */
    public const string DIRECTION_OUTBOUND = 'OUTBOUND';

    /** @var string Both sides hung up normally */
    public const string END_REASON_COMPLETED = 'COMPLETED';

    /** @var string The session ran into its time, daily or budget limit */
    public const string END_REASON_LIMIT_REACHED = 'LIMIT_REACHED';

    /** @var string The session died on an error (provider, model or worker) */
    public const string END_REASON_FAILED = 'FAILED';

    /** @var string An outbound call nobody picked up, where the medium reports that itself */
    public const string END_REASON_NOT_ANSWERED = 'NOT_ANSWERED';

    /** @var string Every client the call rang on declined it */
    public const string END_REASON_DECLINED = 'DECLINED';

    /** @var string Nobody answered before {@see $ringDeadlineAt} passed */
    public const string END_REASON_EXPIRED = 'EXPIRED';

    /** @var string The side that started the call withdrew it before anyone answered */
    public const string END_REASON_CANCELED = 'CANCELED';

    /** @var string The agent ended the call with its `endVoiceCall` tool: a running call hung up, or a ringing one withdrawn */
    public const string END_REASON_ENDED_BY_AGENT = 'ENDED_BY_AGENT';

    /** @var string The call rings and nobody has answered yet ({@see getCallStateAt()}) */
    public const string CALL_STATE_RINGING = 'RINGING';

    /** @var string The call was answered and is running ({@see getCallStateAt()}) */
    public const string CALL_STATE_IN_PROGRESS = 'IN_PROGRESS';

    /** @var string The call is over, or its row was left open by a worker that died ({@see getCallStateAt()}) */
    public const string CALL_STATE_ENDED = 'ENDED';

    /** @var string The call runs in the application's own window over WebRTC; there is no number on either end */
    public const string CHANNEL_TYPE_APP = 'APP';

    /** @var string What the agent is called in the texts of a call when the application's persona names nobody */
    public const string DEFAULT_AGENT_LABEL = 'the agent';

    /**
     * @var string|null The provider's own id for this call's session: the key of every control action and of the
     * session's event channel, so it correlates this row with the live session.
     */
    #[Length(max: 128)]
    public ?string $providerCallId = null;

    /**
     * @var string|null The name of the voice provider adapter this call was created on (a key of
     * `AI.agentVoice.provider.adapters`). Pinned at creation, so that creating, attaching and hanging up resolve to the
     * same adapter even when the configured default changes while the call runs.
     */
    #[Length(max: 20)]
    public ?string $voiceProvider = null;

    /** @var int|null The account this call belongs to, stamped at creation */
    public ?int $accountId = null;

    /**
     * @var DateTime|null When the ringing stops if nobody has answered; the call ends as {@see self::END_REASON_EXPIRED}
     * past this point. Null for a call the owner opened: it is answered the moment it exists.
     */
    public ?DateTime $ringDeadlineAt = null;

    /** @var string Who started the call: INBOUND | OUTBOUND */
    #[Length(max: 20)]
    #[Choice(choices: [self::DIRECTION_INBOUND, self::DIRECTION_OUTBOUND])]
    public string $direction = self::DIRECTION_INBOUND;

    /** @var DateTime|null When the call was answered */
    public ?DateTime $startedAt = null;

    /** @var DateTime|null When the call hung up; null while it is still ringing or running */
    public ?DateTime $endedAt = null;

    /**
     * @var string|null Why it ended. The constants above are not everything the column holds: the teardown paths
     * write diagnostic reasons of their own (a closed or silent session, a failed attach, a reaped row). Not a
     * `Choice`: a call that cannot write its end reason is a call that never closes.
     */
    #[Length(max: 40)]
    public ?string $endReason = null;

    /**
     * @var string|null What the agent works on in the call, when the caller of the call start gave a task. Lives on
     * the call, so that an existing conversation can carry a call without a seeded task message.
     */
    #[Length(max: 2000)]
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    public ?string $callTask = null;

    /** @var int|null The conversation this call runs on */
    public ?int $aiConversationId = null;

    /** @var AIConversation|null The conversation this call runs on */
    #[LazyLoad(repoType: LazyLoadRepo::DB)]
    public ?AIConversation $aiConversation;

    /**
     * @var int|null The conversation in which the agent itself started this call with its `startVoiceCall` tool (the
     * chat's root conversation). Null for every call somebody else started. When such a call ends unanswered, the
     * agent follows up in this conversation ({@see isUnansweredEndTheAgentFollowsUpOn()}). Written alone, as a partial
     * write of this one column, so a concurrent ring or end write is never overwritten.
     */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)] // read by call id only
    public ?int $startedByAgentInConversationId = null;

    /**
     * @var int The seconds of this call booked as usage so far (the provider's cumulative session seconds, in the
     * slices the session worker booked). The daily cap and the admin list read it, so an application without a cost
     * ledger still has the numbers.
     */
    public int $bookedSeconds = 0;

    /** The medium this call runs over. The base knows the application's window only. */
    public function getChannelType(): string
    {
        return static::CHANNEL_TYPE_APP;
    }

    /** True while the call is still running (answered, not yet hung up). */
    public function isRunning(): bool
    {
        return $this->startedAt !== null && $this->endedAt === null;
    }

    /**
     * Where the call stands at `$nowUnixTime`: RINGING (never answered, not ended, still inside its ring window),
     * IN_PROGRESS (answered, not ended, answered less than the maximum call length ago, which is the hard stop of the
     * worker that accompanies the call), ENDED otherwise. A row left open by a dead worker therefore reads ENDED. Pure
     * over the row's own columns and the two limits.
     */
    public function getCallStateAt(int $nowUnixTime): string
    {
        if ($this->endedAt !== null) {
            return static::CALL_STATE_ENDED;
        }
        if ($this->startedAt !== null) {
            return $this->startedAt->getTimestamp() >= $nowUnixTime - $this->getMaximumCallSeconds()
                ? static::CALL_STATE_IN_PROGRESS
                : static::CALL_STATE_ENDED;
        }
        if ($this->ringDeadlineAt !== null) {
            return $this->ringDeadlineAt->getTimestamp() > $nowUnixTime ? static::CALL_STATE_RINGING : static::CALL_STATE_ENDED;
        }
        $createdTime = $this->getCreatedTime();
        return $createdTime !== null && $createdTime->getTimestamp() >= $nowUnixTime - $this->getRingSecondsWithoutDeadline()
            ? static::CALL_STATE_RINGING
            : static::CALL_STATE_ENDED;
    }

    /** True while the call rings or runs at `$nowUnixTime` ({@see getCallStateAt()}). */
    public function isOpenAt(int $nowUnixTime): bool
    {
        return $this->getCallStateAt($nowUnixTime) !== static::CALL_STATE_ENDED;
    }

    /** Seconds since the call was answered, for a call still running at `$nowUnixTime`; null otherwise. */
    public function getRunningSecondsAt(int $nowUnixTime): ?int
    {
        if ($this->getCallStateAt($nowUnixTime) !== static::CALL_STATE_IN_PROGRESS) {
            return null;
        }
        return max(0, $nowUnixTime - $this->startedAt->getTimestamp());
    }

    /** How long the call lasted in seconds; null while it is still running or was never answered. */
    public function getDurationInSeconds(): ?int
    {
        if ($this->startedAt === null || $this->endedAt === null) {
            return null;
        }
        return $this->endedAt->getTimestamp() - $this->startedAt->getTimestamp();
    }

    /** Records that the call was answered. Pure state transition; persisting it is the store's job. */
    public function markStarted(): void
    {
        $this->startedAt = DateTime::fromTimestamp(time());
    }

    /** Records the hang-up and why it happened. Pure state transition; persisting it is the store's job. */
    public function markEnded(string $endReason): void
    {
        $this->endedAt = DateTime::fromTimestamp(time());
        $this->endReason = $endReason;
    }

    /**
     * WHO started the call, in the words the model reads: the owner calling the agent, the agent itself with
     * `startVoiceCall` ({@see $startedByAgentInConversationId}), or a call the agent placed on somebody's request (the
     * owner's "call me" button, an operator's test call, the console).
     */
    public function buildStartedByLabel(): string
    {
        $agentLabel = $this->getAgentLabel();
        if ($this->direction === static::DIRECTION_INBOUND) {
            return 'the owner (called ' . $agentLabel . ' in the app)';
        }
        return $this->startedByAgentInConversationId !== null
            ? $agentLabel . ' (startVoiceCall in conversation ' . $this->startedByAgentInConversationId . ')'
            : $agentLabel . ' on request (the owner\'s call-me button or an operator)';
    }

    /**
     * WHO called WHOM, as one short phrase ("in-app → Assistant"): the shared piece of both text renderings, so the two
     * cannot drift apart.
     */
    public function buildCallPartiesLabel(): string
    {
        $agentLabel = $this->getAgentLabel();
        $otherPartyLabel = $this->getOtherPartyLabel();

        return $this->direction === static::DIRECTION_OUTBOUND
            ? $agentLabel . ' → ' . $otherPartyLabel
            : $otherPartyLabel . ' → ' . $agentLabel;
    }

    /**
     * The lifecycle signal text of this call (started / ended). The model reads it AND the chat's call marker parses it
     * (the duration as `NNNs`, the reason as `reason <value>`, the call as `call #<id>`), so the shape is a small
     * contract with the web half. Pure fold over own columns.
     */
    public function buildLifecycleSignalText(bool $callEnded): string
    {
        $callPartiesLabel = $this->buildCallPartiesLabel();
        $callWord = $this->getCallWord();
        if (!$callEnded) {
            return $callWord . ' started — ' . $callPartiesLabel . ' (call #' . $this->id . ').';
        }
        $endedParts = [$callWord . ' ended — ' . $callPartiesLabel];
        if ($this->getDurationInSeconds() !== null) {
            $endedParts[] = $this->getDurationInSeconds() . 's';
        }
        if ($this->endReason !== null) {
            $endedParts[] = 'reason ' . $this->endReason;
        }

        return implode(', ', $endedParts) . ' (call #' . $this->id . ').';
    }

    /** One call as a single Markdown line, read now and without the marker of the current conversation ({@see buildLlmMarkdownLine()}). */
    public function toLlmMarkdown(): string
    {
        return $this->buildLlmMarkdownLine(time(), false);
    }

    /**
     * One call as a single legible Markdown line (what `getVoiceCalls` relays to the model): id, medium, the parties,
     * the state at `$nowUnixTime` (RINGING / IN_PROGRESS with the seconds so far / ENDED), the answered time and
     * duration, who started it, what a subclass adds ({@see getAdditionalLlmMarkdownLineParts()}), the end reason, the
     * linked conversation (with the marker "THIS conversation's call" when `$isCallOfTheCurrentConversation`) and the
     * task. Pure fold over the row's own columns (no lazy relations: a log of fifty rows would fire a query per line).
     */
    public function buildLlmMarkdownLine(int $nowUnixTime, bool $isCallOfTheCurrentConversation): string
    {
        $callState = $this->getCallStateAt($nowUnixTime);
        $statePart = match ($callState) {
            static::CALL_STATE_RINGING => 'RINGING since ' . ($this->getCreatedTime() ?? '?') . ' (not answered yet)',
            static::CALL_STATE_IN_PROGRESS => 'IN_PROGRESS since ' . $this->startedAt . ' (' . (int)$this->getRunningSecondsAt($nowUnixTime) . 's so far)',
            default => $this->startedAt === null
                ? 'ENDED · never answered'
                : 'ENDED · answered ' . $this->startedAt . ($this->getDurationInSeconds() !== null ? ' · ' . $this->getDurationInSeconds() . 's' : ''),
        };
        $callLineParts = [
            '#' . $this->id,
            $this->getChannelType(),
            $this->buildCallPartiesLabel(),
            $statePart,
            'started by ' . $this->buildStartedByLabel(),
        ];
        foreach ($this->getAdditionalLlmMarkdownLineParts() as $additionalLinePart) {
            $callLineParts[] = $additionalLinePart;
        }
        if ($this->endReason !== null) {
            $callLineParts[] = 'end ' . $this->endReason;
        }
        $conversationPart = $this->aiConversationId !== null
            ? 'conversation ' . $this->aiConversationId
            : 'no conversation';
        if ($isCallOfTheCurrentConversation) {
            $conversationPart .= $callState === static::CALL_STATE_ENDED
                ? ' (this conversation)'
                : ' ← THIS conversation\'s call';
        }
        $callLineParts[] = $conversationPart;
        if ($this->callTask !== null && trim($this->callTask) !== '') {
            $callLineParts[] = 'task: "' . trim($this->callTask) . '"';
        }

        return '- ' . implode(' · ', $callLineParts);
    }

    /**
     * Whether the agent follows up on its own after this call ended: the agent started it
     * ({@see $startedByAgentInConversationId}), nobody answered, and the owner did not refuse it: the ring EXPIRED on
     * every client. A DECLINED ring is the owner's own "no": no follow-up, the call end marker waits for the owner's
     * next turn. An answered call never follows up.
     */
    public function isUnansweredEndTheAgentFollowsUpOn(): bool
    {
        if ($this->startedByAgentInConversationId === null || $this->startedAt !== null || $this->endedAt === null) {
            return false;
        }
        return $this->endReason === static::END_REASON_EXPIRED;
    }

    /**
     * The turn-owing signal of the agent's follow-up after this unanswered call
     * ({@see isUnansweredEndTheAgentFollowsUpOn()}): what happened, the ONE thing to offer (asked of
     * `$agentVoiceCallReachableChannels`, which knows what reaches the owner now), the topic of the call when it had
     * one, and the rule that the agent asks and never calls again on its own in this turn.
     */
    public function buildUnansweredFollowUpSignalText(AgentVoiceCallReachableChannels $agentVoiceCallReachableChannels): string
    {
        $callTopicPart = '';
        $callTask = trim((string)$this->callTask);
        if ($callTask !== '' && $callTask !== static::getDefaultOutboundCallTask()) {
            $callTopicPart = ' The call was meant for: "' . mb_substr($callTask, 0, 300) . '" — if that can be done here in writing, offer that too.';
        }

        return $this->buildUnansweredCallDescription()
            . ' Write the owner ONE short message in their language: say that they did not pick up, then '
            . $agentVoiceCallReachableChannels->buildUnansweredFollowUpOffer($this)
            . $callTopicPart
            . ' Do NOT call again on your own: no startVoiceCall in this turn. Start a call only after the owner says yes in a later message.';
    }

    /** The task text a call start writes when its caller gave none; such a task is not a topic to name in a follow-up. */
    public static function getDefaultOutboundCallTask(): string
    {
        return 'The owner asked to be called. Ask in a friendly way what you can help with, and work the request through on the call.';
    }

    /** What happened to this unanswered call, as the first sentence of the follow-up signal. */
    protected function buildUnansweredCallDescription(): string
    {
        return 'The in-app call you started to the owner (call #' . $this->id . ') was not answered: the app rang for '
            . AgentVoiceConfiguration::current()->ringSeconds . ' seconds and nobody picked up.';
    }

    /**
     * Further parts of the Markdown line of this call, placed after "started by …". None in the base; a subclass with
     * further media adds what its columns say.
     *
     * @return string[]
     */
    protected function getAdditionalLlmMarkdownLineParts(): array
    {
        return [];
    }

    /** The other party of the call as the model reads it. In the application's window there is no number: the medium is named. */
    protected function getOtherPartyLabel(): string
    {
        return 'in-app';
    }

    /** The word for this call in its lifecycle signal. */
    protected function getCallWord(): string
    {
        return 'In-app call';
    }

    /** What the agent is called in the texts of a call: the persona's agent name, else {@see self::DEFAULT_AGENT_LABEL}. */
    protected function getAgentLabel(): string
    {
        $agentName = AgentVoiceConfiguration::current()->agentName;
        return $agentName !== '' ? $agentName : static::DEFAULT_AGENT_LABEL;
    }

    /** The longest a call stays live, in seconds (`AI.agentVoice.limits.maximumCallSeconds`). */
    protected function getMaximumCallSeconds(): int
    {
        return AgentVoiceConfiguration::current()->maximumCallSeconds;
    }

    /**
     * How long a never-answered call WITHOUT a ring deadline still counts as ringing after its row was created. In the
     * base every ring carries a deadline, so this is only the bound of a row that lost it: the configured ring length.
     */
    protected function getRingSecondsWithoutDeadline(): int
    {
        return AgentVoiceConfiguration::current()->ringSeconds;
    }
}
