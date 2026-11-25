<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice\Live;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a voice provider answers when a client's SDP offer creates a session: the SDP the client applies, and the
 * session id every later control action and the attach of the session worker are keyed on.
 *
 * GPT-Live (`POST /v1/live/sessions`) answers both in the response BODY: the id as `session.id` (a `live_…` value) and
 * the answer under `transport.sdp`. So this object reads a JSON body, not a response header. An adapter of a provider
 * that answers another shape fills the two fields itself.
 */
class LiveSessionCreationResponse extends ValueObject
{
    /** @var string The SDP answer the client applies with `setRemoteDescription` */
    public string $sdpAnswer = '';

    /** @var string The provider's session id (`live_…`): the URL of the session worker's channel and every control action use it */
    public string $providerSessionId = '';

    /** True when the provider gave both halves; anything less cannot become a working call. */
    public function isUsable(): bool
    {
        return trim($this->sdpAnswer) !== '' && trim($this->providerSessionId) !== '';
    }

    /**
     * Reads both halves out of the decoded creation response. Tolerant about nesting (`session.id` or a top-level
     * `id`, `transport.sdp` or a top-level `sdp`), because the provider's reference shows the nested form while its
     * examples flatten it; refuses to guess when neither is present.
     *
     * @param array<string, mixed> $creationResponseBody
     */
    public static function fromCreationResponseBody(array $creationResponseBody): static
    {
        $liveSessionCreationResponse = new static();
        $sessionObject = is_array($creationResponseBody['session'] ?? null) ? $creationResponseBody['session'] : [];
        $transportObject = is_array($creationResponseBody['transport'] ?? null)
            ? $creationResponseBody['transport']
            : [];
        $liveSessionCreationResponse->providerSessionId = trim((string)(
            $sessionObject['id'] ?? $creationResponseBody['id'] ?? ''
        ));
        $liveSessionCreationResponse->sdpAnswer = (string)(
            $transportObject['sdp'] ?? $creationResponseBody['sdp'] ?? ''
        );

        return $liveSessionCreationResponse;
    }
}
