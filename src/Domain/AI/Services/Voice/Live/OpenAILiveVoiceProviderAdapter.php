<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice\Live;

use DDD\Domain\AI\Entities\Voice\AgentVoiceSettings;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSeedInputMessage;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSeedInputMessages;
use DDD\Domain\AI\Services\Voice\Providers\RealtimeVoiceProviderAdapter;
use DDD\Infrastructure\Exceptions\BadGatewayException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use stdClass;
use Throwable;

/**
 * GPT-Live (`gpt-live-1.1`), the provider of the LIVE voice family. Everything this class knows is wire shape: the
 * request that creates a session, the URL of the sideband WebSocket, the control actions, the size limits.
 *
 * GPT-Live is driven by the model: it starts and ends its own turns, holds no tools and takes instructions
 * append-only. The backend of a session is the agent (CLIENT delegation), never a model of the provider.
 *
 * The provider is called directly, not through a model gateway: controlling a session is not a model call. The key
 * is the provider project's key, read from the environment variable the adapter's settings name
 * (`apiKeyEnvironmentVariable`); it is never in the config and never logged.
 *
 * The creation request follows the provider's documented shape: exactly two top-level keys, `session` and
 * `transport`; `delegation` and the seed history `input` live INSIDE `session`.
 *
 * Host, model, default voice and the storage flag are the adapter's settings
 * (`AI.agentVoice.provider.<name>`: `apiHost`, `model`, `defaultVoice`, `storeSessionAtProvider`); a missing setting
 * falls back to the constant of this class.
 *
 * Cutting and splitting a text to the size of one append are the seam's ({@see cutToAppendCap()},
 * {@see splitIntoAppendPieces()}); this class answers the size ({@see APPEND_MAX_CHARS}).
 *
 * Override points of an application's subclass at the same path:
 * - which voices the adapter sends: {@see isLiveVoiceId()};
 * - further fields that ask the provider to keep a session: {@see buildSessionStorageFields()};
 * - where a refused control action is logged: {@see appendToVoiceLog()};
 * - the prompt a cut answer ends with: {@see getAppendCutPromptName()};
 * - the HTTP client of the two REST calls: {@see buildHttpClient()};
 * - further session families of the same provider add their own methods.
 */
class OpenAILiveVoiceProviderAdapter extends RealtimeVoiceProviderAdapter
{
    /** @var string The name this adapter is registered under in the package's config file */
    public const string DEFAULT_PROVIDER_NAME = 'openAiLive';

    /** @var string The provider's host; a provider project with a region of its own has its own (`apiHost`) */
    public const string DEFAULT_API_HOST = 'api.openai.com';

    /** @var string The environment variable that holds the key when the settings name none (`apiKeyEnvironmentVariable`) */
    public const string DEFAULT_API_KEY_ENVIRONMENT_VARIABLE = 'API_OPENAI_REALTIME_API_KEY';

    /** @var string The GPT-Live model when the settings name none (`model`) */
    public const string LIVE_MODEL = 'gpt-live-1.1';

    /**
     * @var string The voice GPT-Live speaks with when the settings name none (`defaultVoice`): the documented default
     * of `audio.output.voice`. A voice of another provider is NOT listed for GPT-Live; sending one is the likeliest
     * refusal of a session whose stored voice was picked for another provider.
     */
    public const string DEFAULT_LIVE_VOICE_ID = AgentVoiceSettings::VOICE_MARIN;

    /** @var string The path of the sessions: creation by POST, `{path}/{sessionId}/hangup` and `{path}/{sessionId}/attach` below it */
    protected const string SESSIONS_PATH = '/v1/live/sessions';

    /** @var int Seconds to wait for the SDP answer: a person is looking at a "connecting" screen */
    protected const int SESSION_CREATION_TIMEOUT_SECONDS = 10;

    /** @var int Seconds to wait for a control action */
    protected const int CONTROL_CALL_TIMEOUT_SECONDS = 10;

    /** @var int The seed history GPT-Live accepts at creation: the documented cap on `input` */
    public const int SEED_INPUT_MAX_MESSAGES = 128;

    /** @var int Characters per seed message handed over: one briefing block must never eat the whole budget */
    public const int SEED_INPUT_MESSAGE_MAX_CHARS = 6000;

    /**
     * @var int Characters over the WHOLE seed list. The documented cap is 8,192 tokens combined; prose of a language
     * with long words tokenises at roughly 3–3.5 characters per token, so 20,000 characters stays under it with room.
     * The list is filled in priority order and the last blocks are the first to be cut.
     */
    public const int SEED_INPUT_TOTAL_MAX_CHARS = 20000;

    /** @var int Characters of one `session.*.append`: the documented cap is 500 tokens; at ~3–3.5 characters per token that fits in 1,200 */
    public const int APPEND_MAX_CHARS = 1200;

    public function getApiKey(): string
    {
        return (string)Config::getEnv($this->getApiKeyEnvironmentVariableName());
    }

    /** The Live model: the adapter's `model` setting, else {@see LIVE_MODEL}. */
    public function getLiveModel(): string
    {
        $configuredLiveModel = $this->findProviderSetting('model');

        return is_string($configuredLiveModel) && trim($configuredLiveModel) !== '' ? trim($configuredLiveModel) : static::LIVE_MODEL;
    }

    public function getModelName(): string
    {
        return $this->getLiveModel();
    }

    /** The sideband WebSocket the session worker attaches to. Never `session.start` there: the session already runs. */
    public function buildSidebandWebSocketUrl(string $providerSessionId): string
    {
        return 'wss://' . $this->getApiHost() . static::SESSIONS_PATH . '/' . rawurlencode($providerSessionId) . '/attach';
    }

    /** @return array<string, string> */
    public function buildSidebandWebSocketHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->getApiKey()];
    }

    /**
     * The creation request of a window call: the live prompt (short: role, style, delegation rules), the voice of the
     * call's settings, CLIENT delegation (the agent's own loop is the backend, never a model of the provider), the
     * seed history as `input`, and the client's offer as the WebRTC transport.
     *
     * @param AgentVoiceSeedInputMessages|null $seedInputMessages the seed rows, the most important first; null = none
     * @return array<string, mixed>
     */
    public function buildSessionCreationPayload(
        string $sdpOffer,
        string $liveInstructions,
        ?AgentVoiceSettings $effectiveVoiceSettings,
        ?AgentVoiceSeedInputMessages $seedInputMessages = null
    ): array {
        $sessionCreationPayload = [
            'session' => [
                'model' => $this->getLiveModel(),
                'instructions' => $liveInstructions,
                'audio' => [
                    'output' => [
                        'voice' => $this->resolveLiveVoiceId($effectiveVoiceSettings),
                    ],
                ],
            ],
            'transport' => [
                'type' => 'webrtc',
                'sdp' => $sdpOffer,
            ],
        ];
        // Client delegation: the agent's loop is the backend. Inside `session`, where the provider documents it.
        $sessionCreationPayload['session']['delegation'] = ['type' => 'client'];
        foreach ($this->buildSessionStorageFields() as $storageFieldName => $storageFieldValue) {
            $sessionCreationPayload['session'][$storageFieldName] = $storageFieldValue;
        }

        $seedInputRows = [];
        $remainingSeedChars = static::SEED_INPUT_TOTAL_MAX_CHARS;
        foreach (array_slice($seedInputMessages?->getElements() ?? [], 0, static::SEED_INPUT_MAX_MESSAGES) as $seedInputMessage) {
            if ($remainingSeedChars <= 0) {
                break;
            }
            $seedText = mb_substr(
                trim($seedInputMessage->text),
                0,
                min(static::SEED_INPUT_MESSAGE_MAX_CHARS, $remainingSeedChars)
            );
            if ($seedText === '') {
                continue;
            }
            $remainingSeedChars -= mb_strlen($seedText);
            $seedRole = $seedInputMessage->role;
            // Developer and user rows carry `input_text`; assistant rows carry `output_text` (the documented
            // role/content pairing of the seed list).
            $seedContentType = $seedRole === AgentVoiceSeedInputMessage::ROLE_ASSISTANT ? 'output_text' : 'input_text';
            $seedInputRows[] = [
                'type' => 'message',
                'role' => $seedRole,
                'content' => [['type' => $seedContentType, 'text' => $seedText]],
            ];
        }
        if ($seedInputRows !== []) {
            $sessionCreationPayload['session']['input'] = $seedInputRows;
        }

        return $sessionCreationPayload;
    }

    public function getSeedInputMaximumMessages(): int
    {
        return static::SEED_INPUT_MAX_MESSAGES;
    }

    public function getSeedInputMessageMaximumCharacters(): int
    {
        return static::SEED_INPUT_MESSAGE_MAX_CHARS;
    }

    public function getSeedInputTotalMaximumCharacters(): int
    {
        return static::SEED_INPUT_TOTAL_MAX_CHARS;
    }

    /** The size of one `session.*.append`: a text reaches the model through it, 500 tokens per append. */
    public function getAppendMaximumCharacters(): int
    {
        return static::APPEND_MAX_CHARS;
    }

    /**
     * GPT-Live's `session.closed.reason` as the end reason of the call. The documented values map to the call's
     * constants; anything undocumented is a failure, not a diagnostic string outside the schema. `connection_lost` is
     * what a client that dropped its WebRTC connection arrives as: the transport went away because the other side
     * ended the call, which is a completed call, not a failed one.
     */
    public function mapSessionCloseReasonToEndReason(string $sessionCloseReason): string
    {
        return match ($sessionCloseReason) {
            'remote_hangup', 'close_requested', 'connection_lost' => AgentVoiceCall::END_REASON_COMPLETED,
            'expired' => AgentVoiceCall::END_REASON_LIMIT_REACHED,
            default => AgentVoiceCall::END_REASON_FAILED,
        };
    }

    /**
     * The voice of the session: the stored voice of the call's settings when it IS a GPT-Live voice, else the
     * adapter's default voice. A voice of another provider left in the settings never reaches the wire.
     */
    public function resolveLiveVoiceId(?AgentVoiceSettings $effectiveVoiceSettings): string
    {
        $storedVoiceId = $effectiveVoiceSettings?->voiceId;
        if ($storedVoiceId !== null && $this->isLiveVoiceId($storedVoiceId)) {
            return $storedVoiceId;
        }

        return $this->getDefaultLiveVoiceId();
    }

    /**
     * Hands the client's SDP offer to GPT-Live and returns the answer plus the session id the sideband attaches to.
     * The offer is proxied through the application, so that no provider credential reaches a client and the prompt is
     * composed on the server.
     *
     * @param array<string, mixed> $sessionCreationPayload from {@see buildSessionCreationPayload()}
     * @throws BadGatewayException when the key is missing (nothing is sent then), when GPT-Live refuses the offer,
     *     answers without a usable session id or cannot be reached (`provider_unavailable`); what it said is read with
     *     {@see describeProviderFailure()}
     */
    public function createSessionFromSdpOffer(array $sessionCreationPayload): LiveSessionCreationResponse
    {
        if (trim($this->getApiKey()) === '') {
            // No request without a credential: the offer carries the composed prompt of the call.
            throw $this->buildProviderUnavailableException(
                'GPT-Live was not asked: the environment variable ' . $this->getApiKeyEnvironmentVariableName()
                . ' holds no key (read through Config::getEnv(), that is from $_ENV)'
            );
        }
        $httpClient = $this->buildHttpClient(static::SESSION_CREATION_TIMEOUT_SECONDS);
        try {
            $response = $httpClient->post('https://' . $this->getApiHost() . static::SESSIONS_PATH, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getApiKey(),
                    'Content-Type' => 'application/json',
                ],
                'json' => $sessionCreationPayload,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $transportFailure) {
            // A timeout or a refused connection is the same answer for the caller as a refusal: no session.
            throw $this->buildProviderUnavailableException(
                'GPT-Live could not be reached with the SDP offer: ' . $transportFailure->getMessage(),
                $transportFailure
            );
        }

        if ($response->getStatusCode() >= 300) {
            throw $this->buildProviderUnavailableException(
                'GPT-Live refused the SDP offer (HTTP ' . $response->getStatusCode() . '): '
                . mb_substr((string)$response->getBody(), 0, 500)
            );
        }
        $creationResponseBody = json_decode((string)$response->getBody(), true);
        if (!is_array($creationResponseBody)) {
            throw $this->buildProviderUnavailableException('GPT-Live accepted the SDP offer but answered with a non-JSON body.');
        }
        $liveSessionCreationResponse = LiveSessionCreationResponse::fromCreationResponseBody($creationResponseBody);
        if (!$liveSessionCreationResponse->isUsable()) {
            throw $this->buildProviderUnavailableException(
                'GPT-Live accepted the SDP offer but answered without a usable session id or SDP — keys: '
                . implode(',', array_keys($creationResponseBody))
            );
        }

        return $liveSessionCreationResponse;
    }

    /**
     * Whether a failed sideband attach says the session itself is GONE (the provider's `session_id_not_found`, an
     * HTTP 404 on the attach URL), as opposed to a transient network or 5xx failure worth another attempt.
     */
    public function isSessionGoneConnectFailure(Throwable $connectFailure): bool
    {
        $failureMessage = $connectFailure->getMessage();

        return str_contains($failureMessage, 'session_id_not_found')
            || (bool)preg_match('~^HTTP/\d(?:\.\d)? 404\b~', $failureMessage);
    }

    /**
     * HANG UP a running session (`POST /v1/live/sessions/{id}/hangup`); true on a 2xx. A session that is already gone
     * answers non-2xx and nothing happens, which is the idempotency a hang-up relies on.
     */
    public function hangUpSession(string $providerSessionId): bool
    {
        return $this->postSessionControlAction($providerSessionId, 'hangup', []);
    }

    /**
     * POST one session control action with the project key; true on a 2xx.
     *
     * @param array<string, mixed> $requestBody
     */
    protected function postSessionControlAction(string $providerSessionId, string $action, array $requestBody): bool
    {
        $httpClient = $this->buildHttpClient(static::CONTROL_CALL_TIMEOUT_SECONDS);
        $response = $httpClient->post(
            'https://' . $this->getApiHost() . static::SESSIONS_PATH . '/' . rawurlencode($providerSessionId) . '/' . $action,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getApiKey(),
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestBody === [] ? new stdClass() : $requestBody,
                'http_errors' => false,
            ]
        );
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 300) {
            // The provider's refusal is the one line that explains an action that did not take effect: logged with
            // the body (never the key) and what was sent minus the prompt.
            $sentSummary = $requestBody;
            if (isset($sentSummary['session']['instructions'])) {
                $sentSummary['session']['instructions'] = '<' . mb_strlen((string)$sentSummary['session']['instructions']) . ' chars>';
            }
            $this->appendToVoiceLog(
                "LIVE {$providerSessionId} {$action} REFUSED HTTP {$statusCode}: "
                . mb_substr((string)$response->getBody(), 0, 1500)
                . ' — sent ' . mb_substr((string)json_encode($sentSummary), 0, 800)
            );
        }

        return $statusCode >= 200 && $statusCode < 300;
    }

    /**
     * One line of the voice channel's log. The base writes it as a warning of the application's logger; an application
     * with a log of its own for the voice channel writes it there. A failed log write never affects the call.
     */
    protected function appendToVoiceLog(string $voiceLogLine): void
    {
        try {
            DDDService::instance()->getLogger()->warning($voiceLogLine);
        } catch (Throwable) {
            // A failed log write must never affect the call.
        }
    }

    /** Whether GPT-Live knows the voice: the adapter's default voice or one of the provider's catalog. */
    protected function isLiveVoiceId(string $voiceId): bool
    {
        return $voiceId === $this->getDefaultLiveVoiceId()
            || $voiceId === AgentVoiceSettings::VOICE_MARIN
            || in_array($voiceId, AgentVoiceSettings::SELECTABLE_LIVE_VOICE_IDS, true);
    }

    /** The voice GPT-Live speaks with when the call's settings name none of its own: `defaultVoice`, else {@see DEFAULT_LIVE_VOICE_ID}. */
    protected function getDefaultLiveVoiceId(): string
    {
        $configuredDefaultVoiceId = $this->findProviderSetting('defaultVoice');

        return is_string($configuredDefaultVoiceId) && trim($configuredDefaultVoiceId) !== ''
            ? trim($configuredDefaultVoiceId)
            : static::DEFAULT_LIVE_VOICE_ID;
    }

    /** The provider's host: `apiHost`, else {@see DEFAULT_API_HOST}. */
    protected function getApiHost(): string
    {
        $configuredApiHost = $this->findProviderSetting('apiHost');

        return is_string($configuredApiHost) && trim($configuredApiHost) !== '' ? trim($configuredApiHost) : static::DEFAULT_API_HOST;
    }

    /** The environment variable that holds the key: `apiKeyEnvironmentVariable`, else {@see DEFAULT_API_KEY_ENVIRONMENT_VARIABLE}. */
    protected function getApiKeyEnvironmentVariableName(): string
    {
        $configuredVariableName = $this->findProviderSetting('apiKeyEnvironmentVariable');

        return is_string($configuredVariableName) && trim($configuredVariableName) !== ''
            ? trim($configuredVariableName)
            : static::DEFAULT_API_KEY_ENVIRONMENT_VARIABLE;
    }

    /** Whether the provider is asked to keep the session (`storeSessionAtProvider`); false when not set. */
    protected function isSessionStoredAtProvider(): bool
    {
        return $this->findProviderSetting('storeSessionAtProvider') === true;
    }

    /**
     * The fields of the `session` object that ask the provider to keep the session: `store`. The provider's API
     * reference documents it on the page "Create session" (`POST /live/sessions`,
     * https://developers.openai.com/api/reference/resources/live/methods/create) as an optional boolean of `session`:
     * "Whether to store the session for later forking and recording download. Defaults to false for new sessions."
     * The provider's data controls page (https://developers.openai.com/api/docs/guides/your-data) gives the
     * application state retention of `/v1/live/sessions` as "None, or 30 days if stored".
     *
     * Nothing is sent while `storeSessionAtProvider` is false: false is the documented default, and the request stays
     * the one without the switch. Override point: further retention fields of an application's provider project.
     *
     * @return array<string, mixed>
     */
    protected function buildSessionStorageFields(): array
    {
        return $this->isSessionStoredAtProvider() ? ['store' => true] : [];
    }

    protected function getDefaultProviderName(): string
    {
        return static::DEFAULT_PROVIDER_NAME;
    }

    /** The HTTP client of one REST call to the provider. */
    protected function buildHttpClient(int $timeoutSeconds): Client
    {
        return new Client(['timeout' => $timeoutSeconds]);
    }
}
