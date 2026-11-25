<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Agents\Voice;

use DDD\Domain\AI\Services\Voice\Live\LiveSessionCreationResponse;
use DDD\Domain\AI\Services\Voice\Live\OpenAILiveVoiceProviderAdapter;
use Throwable;

/**
 * The package's adapter without the network: the session creation records the payload it was handed and answers the
 * session the test scripted ({@see $providerSessionId}, {@see $sdpAnswer}), or fails the way the adapter fails
 * ({@see $providerFailureText}: the adapter's own provider-unavailable exception with the provider's words in the
 * previous one), or throws {@see $foreignFailure} as it is.
 */
class ScriptedSessionVoiceProviderAdapter extends OpenAILiveVoiceProviderAdapter
{
    public const string PROVIDER_SESSION_ID = 'live_session_1';

    public const string SDP_ANSWER = "v=0\r\no=answer 1 1 IN IP4 127.0.0.1\r\n";

    /** @var array<int, array<string, mixed>> The payloads the session creation was handed, in order */
    public array $sessionCreationPayloads = [];

    public string $providerSessionId = self::PROVIDER_SESSION_ID;

    public string $sdpAnswer = self::SDP_ANSWER;

    /** @var string|null When set, the creation fails as the adapter fails, with these words of the provider */
    public ?string $providerFailureText = null;

    /** @var Throwable|null When set, the creation throws this unchanged (an application adapter's own exception or error) */
    public ?Throwable $foreignFailure = null;

    public function createSessionFromSdpOffer(array $sessionCreationPayload): LiveSessionCreationResponse
    {
        $this->sessionCreationPayloads[] = $sessionCreationPayload;
        if ($this->foreignFailure !== null) {
            throw $this->foreignFailure;
        }
        if ($this->providerFailureText !== null) {
            throw $this->buildProviderUnavailableException($this->providerFailureText);
        }

        return LiveSessionCreationResponse::fromCreationResponseBody([
            'session' => ['id' => $this->providerSessionId],
            'transport' => ['sdp' => $this->sdpAnswer],
        ]);
    }
}
