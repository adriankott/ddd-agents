<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Voice\AgentVoiceSettings;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSeedInputMessages;
use DDD\Domain\AI\Services\Voice\Live\LiveSessionCreationResponse;
use DDD\Domain\AI\Services\Voice\Providers\RealtimeVoiceProviderAdapter;
use LogicException;
use Throwable;

/**
 * A provider adapter without a provider. The sideband URL of each connect attempt is scripted: an address with a scheme
 * the WebSocket client does not know is refused at once, without a network ("Cannot connect to invalid URL (…)"), so a
 * connect attempt fails on the spot. A failure whose message contains {@see SESSION_GONE_MARKER} counts as a session
 * that is gone at the provider. Hang-ups and the close reasons asked are kept.
 */
class RecordingVoiceProviderAdapter extends RealtimeVoiceProviderAdapter
{
    public const string SESSION_GONE_MARKER = 'session-gone';

    /** @var string[] The sideband URL of each connect attempt, in order; the last one repeats */
    public array $sidebandWebSocketUrls = ['invalid-scheme://sideband-attempt'];

    /** @var string[] Every provider session id the sideband URL was built for */
    public array $sidebandWebSocketUrlRequests = [];

    /** @var string[] Every message checked by {@see isSessionGoneConnectFailure()} */
    public array $checkedConnectFailureMessages = [];

    /** @var string[] Every session hung up through the provider's REST call */
    public array $hungUpProviderSessionIds = [];

    public bool $hangUpAccepted = true;

    /** @var string[] Every close reason mapped */
    public array $mappedSessionCloseReasons = [];

    /** @var array<string, string> close reason => end reason; anything else maps to FAILED */
    public array $endReasonsBySessionCloseReason = ['completed_by_the_provider' => AgentVoiceCall::END_REASON_COMPLETED];

    public int $appendMaximumCharacters = 60;

    public function getModelName(): string
    {
        return 'scripted-live-model';
    }

    public function buildSessionCreationPayload(
        string $sdpOffer,
        string $liveInstructions,
        ?AgentVoiceSettings $effectiveVoiceSettings,
        ?AgentVoiceSeedInputMessages $seedInputMessages = null
    ): array {
        throw new LogicException('The session worker never creates a session.');
    }

    public function createSessionFromSdpOffer(array $sessionCreationPayload): LiveSessionCreationResponse
    {
        throw new LogicException('The session worker never creates a session.');
    }

    public function buildSidebandWebSocketUrl(string $providerSessionId): string
    {
        $this->sidebandWebSocketUrlRequests[] = $providerSessionId;
        $attemptIndex = min(count($this->sidebandWebSocketUrlRequests), count($this->sidebandWebSocketUrls)) - 1;

        return $this->sidebandWebSocketUrls[$attemptIndex];
    }

    public function buildSidebandWebSocketHeaders(): array
    {
        return ['X-Scripted' => 'yes'];
    }

    public function isSessionGoneConnectFailure(Throwable $connectFailure): bool
    {
        $this->checkedConnectFailureMessages[] = $connectFailure->getMessage();

        return str_contains($connectFailure->getMessage(), static::SESSION_GONE_MARKER);
    }

    public function mapSessionCloseReasonToEndReason(string $sessionCloseReason): string
    {
        $this->mappedSessionCloseReasons[] = $sessionCloseReason;

        return $this->endReasonsBySessionCloseReason[$sessionCloseReason] ?? AgentVoiceCall::END_REASON_FAILED;
    }

    public function hangUpSession(string $providerSessionId): bool
    {
        $this->hungUpProviderSessionIds[] = $providerSessionId;

        return $this->hangUpAccepted;
    }

    public function getSeedInputMaximumMessages(): int
    {
        return 10;
    }

    public function getSeedInputMessageMaximumCharacters(): int
    {
        return 500;
    }

    public function getSeedInputTotalMaximumCharacters(): int
    {
        return 2000;
    }

    public function getAppendMaximumCharacters(): int
    {
        return $this->appendMaximumCharacters;
    }

    protected function getDefaultProviderName(): string
    {
        return 'scriptedLive';
    }
}
