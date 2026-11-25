<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice\Providers;

use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\AgentVoiceSettings;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSeedInputMessages;
use DDD\Domain\AI\Entities\Voice\Prompts\AgentVoiceAIPromptNames;
use DDD\Domain\AI\Entities\Voice\Prompts\AgentVoicePromptText;
use DDD\Domain\AI\Services\Voice\Live\LiveSessionCreationResponse;
use DDD\Infrastructure\Exceptions\BadGatewayException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use RuntimeException;
use Throwable;

/**
 * The seam between a voice call in the application's window and the provider whose model speaks: everything such a
 * call needs from a provider, and nothing a provider-specific session dialect adds.
 *
 * A window call needs five things:
 * - the session, created from the client's SDP offer with the instructions, the voice and the seed rows
 *   ({@see buildSessionCreationPayload()}, {@see createSessionFromSdpOffer()});
 * - the event channel the session worker attaches to ({@see buildSidebandWebSocketUrl()},
 *   {@see buildSidebandWebSocketHeaders()}, {@see isSessionGoneConnectFailure()},
 *   {@see mapSessionCloseReasonToEndReason()});
 * - hanging up ({@see hangUpSession()});
 * - the size limits of the seed ({@see getSeedInputMaximumMessages()}, {@see getSeedInputMessageMaximumCharacters()},
 *   {@see getSeedInputTotalMaximumCharacters()});
 * - the size limit of one text appended while the call runs ({@see getAppendMaximumCharacters()},
 *   {@see cutToAppendCap()}, {@see splitIntoAppendPieces()}).
 *
 * What only a provider can answer is abstract: its model, the wire shape of a session, its event channel, how a gone
 * session and a closed one look, hanging up, its size limits. What follows from those answers has a default here:
 * cutting and splitting a text to the size of one append, the name an adapter is registered under, its settings, and
 * the one exception of a provider that fails ({@see buildProviderUnavailableException()},
 * {@see describeProviderFailure()}).
 *
 * The session is driven by the MODEL: it starts and ends its own turns, holds no tools and takes instructions
 * append-only; a request it cannot answer is delegated to the agent by the session worker. So the seam knows no accept
 * payload, no tool definitions and no per-turn events to normalise.
 *
 * An adapter is registered by name in `AI.agentVoice.provider.adapters` and resolved by the name pinned on the call
 * ({@see forCall()}), so that creating, attaching and hanging up reach the same adapter even when the configured
 * default changes while the call runs. Its settings are the entry of that name
 * (`AI.agentVoice.provider.<name>`, {@see findProviderSetting()}); a key is never in the config, an adapter reads it
 * from the environment variable its settings name.
 *
 * Override points: an application registers further adapters (subclasses of this class) in its config file, or binds a
 * subclass of a shipped adapter at the same path. An adapter whose provider cuts differently overrides
 * {@see cutToAppendCap()} and {@see splitIntoAppendPieces()}; the sentence a cut text ends with is the prompt of
 * {@see getAppendCutPromptName()}.
 */
abstract class RealtimeVoiceProviderAdapter extends Service
{
    /** @var string The error of a call whose provider name no registered adapter answers to */
    public const string ERROR_VOICE_PROVIDER_NOT_REGISTERED = 'voice_provider_not_registered';

    /** @var string The error of a provider name whose adapter class is registered under an earlier name as well */
    public const string ERROR_VOICE_PROVIDER_ADAPTER_REGISTERED_TWICE = 'voice_provider_adapter_registered_twice';

    /** @var string The error of a provider that refused a session, answered without a usable one or could not be reached */
    public const string ERROR_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    /** @var float A cut is moved back to a sentence end only if that keeps at least this share of the cap: never a stub */
    protected const float APPEND_CUT_MIN_FRACTION = 0.5;

    /** The adapter of a call: the one registered under the name pinned on it, else the configured default. */
    public static function forCall(AgentVoiceCall $agentVoiceCall): RealtimeVoiceProviderAdapter
    {
        return static::forProviderName($agentVoiceCall->voiceProvider);
    }

    /**
     * The adapter SERVICE registered under a provider name; null resolves to the configured default
     * (`AI.agentVoice.provider.default`). Resolved from the container, so that an application's subclass at the same
     * path answers.
     *
     * An adapter is one service and reads the settings of the ONE name it answers to ({@see getProviderName()}). A
     * class registered under a second name would silently run that name's calls on the first name's host, model and
     * key, so the second name is refused here: a second endpoint of the same provider is a subclass of the adapter,
     * registered under its own name. The same holds for a subclass that an application binds at the path of a
     * registered adapter AND registers under a name of its own: it answers to its own name, and the name of the
     * adapter it stands in for is refused.
     *
     * @throws InternalErrorException when no adapter is registered under the name
     *     ({@see self::ERROR_VOICE_PROVIDER_NOT_REGISTERED}), or when the adapter registered under it answers to
     *     another name ({@see self::ERROR_VOICE_PROVIDER_ADAPTER_REGISTERED_TWICE})
     */
    public static function forProviderName(?string $voiceProviderName): RealtimeVoiceProviderAdapter
    {
        $registeredProviderName = static::findRegisteredProviderName($voiceProviderName);
        $adapterClassName = $registeredProviderName !== null
            ? AgentVoiceConfiguration::current()->findProviderAdapterClassName($registeredProviderName)
            : null;
        if ($adapterClassName === null || !is_a($adapterClassName, RealtimeVoiceProviderAdapter::class, true)) {
            throw new InternalErrorException(static::ERROR_VOICE_PROVIDER_NOT_REGISTERED);
        }
        /** @var RealtimeVoiceProviderAdapter $realtimeVoiceProviderAdapter */
        $realtimeVoiceProviderAdapter = DDDService::instance()->getService($adapterClassName);
        if ($realtimeVoiceProviderAdapter->getProviderName() !== $registeredProviderName) {
            throw new InternalErrorException(static::ERROR_VOICE_PROVIDER_ADAPTER_REGISTERED_TWICE);
        }

        return $realtimeVoiceProviderAdapter;
    }

    /**
     * The name an adapter is registered under for the given provider name: the name itself when it is registered, else
     * the registered name that differs only in spelling ({@see AgentVoiceSettings::normalizeVoiceProviderName()}: a
     * stored `OPENAI_LIVE` finds the adapter registered as `openAiLive`). Null or empty resolves the configured
     * default. Null when no adapter answers to the name.
     */
    public static function findRegisteredProviderName(?string $voiceProviderName): ?string
    {
        $agentVoiceConfiguration = AgentVoiceConfiguration::current();
        $requestedProviderName = $voiceProviderName === null || trim($voiceProviderName) === ''
            ? $agentVoiceConfiguration->defaultProviderName
            : trim($voiceProviderName);
        if ($agentVoiceConfiguration->findProviderAdapterClassName($requestedProviderName) !== null) {
            return $requestedProviderName;
        }
        $normalizedRequestedProviderName = AgentVoiceSettings::normalizeVoiceProviderName($requestedProviderName);
        if ($normalizedRequestedProviderName === '') {
            return null;
        }
        foreach (array_keys($agentVoiceConfiguration->providerAdapterClassNames) as $registeredProviderName) {
            if (AgentVoiceSettings::normalizeVoiceProviderName($registeredProviderName) === $normalizedRequestedProviderName) {
                return $registeredProviderName;
            }
        }

        return null;
    }

    /**
     * The name this adapter is registered under (`AI.agentVoice.provider.adapters`): the name a new call pins and the
     * key of the adapter's settings. The name its own class is registered under comes first, so a subclass registered
     * under a name of its own answers to that name and not to its parent's. An application's subclass bound at the
     * path of a registered adapter is registered under no name of its own and answers to the name of the adapter it
     * stands in for. An adapter that is not registered answers {@see getDefaultProviderName()}.
     */
    public function getProviderName(): string
    {
        $providerAdapterClassNames = AgentVoiceConfiguration::current()->providerAdapterClassNames;
        foreach ($providerAdapterClassNames as $registeredProviderName => $adapterClassName) {
            if ($adapterClassName === static::class) {
                return $registeredProviderName;
            }
        }
        foreach ($providerAdapterClassNames as $registeredProviderName => $adapterClassName) {
            if (is_a($this, $adapterClassName)) {
                return $registeredProviderName;
            }
        }

        return $this->getDefaultProviderName();
    }

    /** The catalog model of the provider's session: the model a session is created on and its seconds are priced by. */
    abstract public function getModelName(): string;

    /**
     * The request that creates the session of a call: the instructions, the voice of the call's settings, the seed
     * rows and the client's offer.
     *
     * @param AgentVoiceSeedInputMessages|null $seedInputMessages the seed rows, the most important first; null = none
     * @return array<string, mixed>
     */
    abstract public function buildSessionCreationPayload(
        string $sdpOffer,
        string $liveInstructions,
        ?AgentVoiceSettings $effectiveVoiceSettings,
        ?AgentVoiceSeedInputMessages $seedInputMessages = null
    ): array;

    /**
     * Hands the client's SDP offer to the provider and returns the answer plus the session id every later action is
     * keyed on. The offer is proxied through the application, so that no provider credential reaches a client and the
     * instructions are composed on the server.
     *
     * @param array<string, mixed> $sessionCreationPayload from {@see buildSessionCreationPayload()}
     * @throws BadGatewayException when the provider refuses the offer, answers without a usable session or cannot be
     *     reached ({@see self::ERROR_PROVIDER_UNAVAILABLE}, built with {@see buildProviderUnavailableException()}).
     *     What the provider said is for the call's trace and read with {@see describeProviderFailure()}.
     */
    abstract public function createSessionFromSdpOffer(array $sessionCreationPayload): LiveSessionCreationResponse;

    /** The URL of the event channel of a running session, which the session worker attaches to. */
    abstract public function buildSidebandWebSocketUrl(string $providerSessionId): string;

    /**
     * The headers of the attach of the session worker.
     *
     * @return array<string, string>
     */
    abstract public function buildSidebandWebSocketHeaders(): array;

    /**
     * Whether a failed attach says that the session itself is GONE, as opposed to a transient network or server
     * failure worth another attempt.
     */
    abstract public function isSessionGoneConnectFailure(Throwable $connectFailure): bool;

    /** The provider's reason for a closed session as an end reason of the call (`AgentVoiceCall::END_REASON_*`). */
    abstract public function mapSessionCloseReasonToEndReason(string $sessionCloseReason): string;

    /**
     * Hangs up a running session; true when the provider confirmed. A session that is already gone answers false and
     * nothing happens: hanging up is idempotent.
     */
    abstract public function hangUpSession(string $providerSessionId): bool;

    /** How many seed rows the provider accepts when a session is created. */
    abstract public function getSeedInputMaximumMessages(): int;

    /** The characters of one seed row the adapter hands over at most: one block must never eat the whole seed. */
    abstract public function getSeedInputMessageMaximumCharacters(): int;

    /** The characters of the whole seed the adapter hands over at most. */
    abstract public function getSeedInputTotalMaximumCharacters(): int;

    /** The characters of one text appended to a running session at most. */
    abstract public function getAppendMaximumCharacters(): int;

    /**
     * A text longer than the size of one append ({@see getAppendMaximumCharacters()}), cut at the last sentence end
     * above half that size and marked as cut: the chat holds the full text anyway.
     */
    public function cutToAppendCap(string $appendText): string
    {
        $appendMaximumCharacters = $this->getAppendMaximumCharacters();
        $appendText = trim($appendText);
        if (mb_strlen($appendText) <= $appendMaximumCharacters) {
            return $appendText;
        }
        // The suffix is part of the append: the cut leaves room for it, so the whole event stays under the cap.
        $appendCutSuffix = $this->getAppendCutSuffix();
        $cutText = mb_substr($appendText, 0, $appendMaximumCharacters - mb_strlen($appendCutSuffix));
        $lastSentenceEndPosition = max(
            (int)mb_strrpos($cutText, '. '),
            (int)mb_strrpos($cutText, '! '),
            (int)mb_strrpos($cutText, '? ')
        );
        if ($lastSentenceEndPosition > (int)($appendMaximumCharacters * static::APPEND_CUT_MIN_FRACTION)) {
            $cutText = mb_substr($cutText, 0, $lastSentenceEndPosition + 1);
        }

        return $cutText . $appendCutSuffix;
    }

    /**
     * A long text as a list of pieces of the size of one append ({@see getAppendMaximumCharacters()}), in order, each
     * cut at a sentence end where one exists above half that size: a block of several thousand characters becomes
     * several appends in order instead of one truncated one.
     *
     * @return array<int, string>
     */
    public function splitIntoAppendPieces(string $appendText): array
    {
        $appendMaximumCharacters = $this->getAppendMaximumCharacters();
        $remainingText = trim($appendText);
        $appendPieces = [];
        while ($remainingText !== '') {
            if (mb_strlen($remainingText) <= $appendMaximumCharacters) {
                $appendPieces[] = $remainingText;
                break;
            }
            $pieceText = mb_substr($remainingText, 0, $appendMaximumCharacters);
            $lastSentenceEndPosition = max(
                (int)mb_strrpos($pieceText, '. '),
                (int)mb_strrpos($pieceText, "\n"),
                (int)mb_strrpos($pieceText, '! '),
                (int)mb_strrpos($pieceText, '? ')
            );
            if ($lastSentenceEndPosition > (int)($appendMaximumCharacters * static::APPEND_CUT_MIN_FRACTION)) {
                $pieceText = mb_substr($pieceText, 0, $lastSentenceEndPosition + 1);
            }
            $appendPieces[] = trim($pieceText);
            $remainingText = trim(mb_substr($remainingText, mb_strlen($pieceText)));
        }

        return $appendPieces;
    }

    /**
     * What the provider said when a session action failed with {@see buildProviderUnavailableException()}: the text
     * for the call's trace and the log, never for a client. Any other failure answers its own message.
     */
    public function describeProviderFailure(Throwable $providerFailure): string
    {
        $providerFailureText = $providerFailure instanceof BadGatewayException
            ? $providerFailure->getPrevious()?->getMessage()
            : null;

        return $providerFailureText !== null && trim($providerFailureText) !== ''
            ? $providerFailureText
            : $providerFailure->getMessage();
    }

    /** The name this adapter has while no config entry registers it. */
    abstract protected function getDefaultProviderName(): string;

    /** One setting of this adapter (`AI.agentVoice.provider.<provider name>.<setting name>`); null when it is not set. */
    protected function findProviderSetting(string $settingName): mixed
    {
        return AgentVoiceConfiguration::current()->findProviderSetting($this->getProviderName(), $settingName);
    }

    /**
     * The exception of a provider that refused, answered unusably or could not be reached: the error code as message
     * ({@see self::ERROR_PROVIDER_UNAVAILABLE}, which a route answers as it is), and what the provider said as the
     * message of the previous exception, so that it reaches the trace and the log but no client
     * ({@see describeProviderFailure()}).
     *
     * @param string $providerFailureText what the provider said, or what kept the request from reaching it; never a key
     * @param Throwable|null $providerFailure the failure of the transport, when there is one
     */
    protected function buildProviderUnavailableException(
        string $providerFailureText,
        ?Throwable $providerFailure = null
    ): BadGatewayException {
        return new BadGatewayException(
            static::ERROR_PROVIDER_UNAVAILABLE,
            previous: new RuntimeException($providerFailureText, 0, $providerFailure)
        );
    }

    /** What a cut append ends with (the chat holds the whole text), with its leading space; empty without the prompt. */
    protected function getAppendCutSuffix(): string
    {
        $appendCutText = AgentVoicePromptText::forName($this->getAppendCutPromptName());

        return $appendCutText !== '' ? ' ' . $appendCutText : '';
    }

    /** The prompt of the sentence a cut append ends with. */
    protected function getAppendCutPromptName(): string
    {
        return AgentVoiceAIPromptNames::APP_AI_VOICE_ANSWER_CUT;
    }
}
