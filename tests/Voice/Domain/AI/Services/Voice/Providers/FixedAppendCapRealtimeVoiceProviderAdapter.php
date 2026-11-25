<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Providers;

use DDD\Domain\AI\Entities\Voice\AgentVoiceSettings;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSeedInputMessages;
use DDD\Domain\AI\Services\Voice\Live\LiveSessionCreationResponse;
use DDD\Domain\AI\Services\Voice\Providers\RealtimeVoiceProviderAdapter;
use DDD\Infrastructure\Exceptions\BadGatewayException;
use Throwable;

/**
 * An adapter of the seam with a small append size ({@see $appendMaximumCharacters}) and the prompt of the cut
 * sentence to choose ({@see $appendCutPromptName}), so that the seam's cutting and splitting can be read in short
 * texts. No provider behind it: the provider's own methods answer fixed values.
 */
class FixedAppendCapRealtimeVoiceProviderAdapter extends RealtimeVoiceProviderAdapter
{
    public const string DEFAULT_PROVIDER_NAME = 'fixedCapTest';

    public int $appendMaximumCharacters = 40;

    public ?string $appendCutPromptName = null;

    /** Exposes the seam's exception of a provider that failed. */
    public function buildProviderUnavailableExceptionForTest(string $providerFailureText, ?Throwable $providerFailure = null): BadGatewayException
    {
        return $this->buildProviderUnavailableException($providerFailureText, $providerFailure);
    }

    /** Exposes one setting of this adapter. */
    public function findProviderSettingForTest(string $settingName): mixed
    {
        return $this->findProviderSetting($settingName);
    }

    public function getModelName(): string
    {
        return 'fixed-model';
    }

    public function buildSessionCreationPayload(string $sdpOffer, string $liveInstructions, ?AgentVoiceSettings $effectiveVoiceSettings, ?AgentVoiceSeedInputMessages $seedInputMessages = null): array
    {
        return [];
    }

    public function createSessionFromSdpOffer(array $sessionCreationPayload): LiveSessionCreationResponse
    {
        return new LiveSessionCreationResponse();
    }

    public function buildSidebandWebSocketUrl(string $providerSessionId): string
    {
        return '';
    }

    public function buildSidebandWebSocketHeaders(): array
    {
        return [];
    }

    public function isSessionGoneConnectFailure(Throwable $connectFailure): bool
    {
        return false;
    }

    public function mapSessionCloseReasonToEndReason(string $sessionCloseReason): string
    {
        return AgentVoiceCall::END_REASON_FAILED;
    }

    public function hangUpSession(string $providerSessionId): bool
    {
        return false;
    }

    public function getSeedInputMaximumMessages(): int
    {
        return 1;
    }

    public function getSeedInputMessageMaximumCharacters(): int
    {
        return 1;
    }

    public function getSeedInputTotalMaximumCharacters(): int
    {
        return 1;
    }

    public function getAppendMaximumCharacters(): int
    {
        return $this->appendMaximumCharacters;
    }

    protected function getDefaultProviderName(): string
    {
        return static::DEFAULT_PROVIDER_NAME;
    }

    protected function getAppendCutPromptName(): string
    {
        return $this->appendCutPromptName ?? parent::getAppendCutPromptName();
    }
}
