<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Exceptions\MissingAIEnvelopeException;
use DDD\Domain\AI\Services\AIModelCallGateService;

/**
 * The application's model-call gate: the package default (nothing refused) unless {@see $refusesForAMissingEnvelope},
 * then every call is refused for a missing usage envelope — our own gate's refusal, thrown while the payload is built.
 */
class ScriptedModelCallGateDouble extends AIModelCallGateService
{
    public bool $refusesForAMissingEnvelope = false;

    public function refuseModelCallWithoutUsageScopeOrEnvelope(AIModelCall $aiModelCall): void
    {
        if ($this->refusesForAMissingEnvelope) {
            throw new MissingAIEnvelopeException(self::class);
        }
    }
}
