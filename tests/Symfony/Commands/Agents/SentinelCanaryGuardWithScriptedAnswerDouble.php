<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Repo\Argus\Guard\ArgusAgentSentinelCanaryGuard;

/**
 * A guard that calls no model: its load answers the scripted raw text, and it records the model it was given.
 */
class SentinelCanaryGuardWithScriptedAnswerDouble extends ArgusAgentSentinelCanaryGuard
{
    public string $scriptedRawResponse = '';

    public ?string $givenModelName = null;

    public function setModelByName(string $modelName): void
    {
        $this->givenModelName = $modelName;
    }

    public function argusLoad(
        bool $useArgusEntityCache = true,
        bool $useApiACallCache = true,
        bool $displayCall = false,
        bool $displayResponse = false,
        bool $autoloadCurrentObject = true,
        ?int $apiCallCacheLifetime = null
    ): void {
        $this->applyLoadResult($this->scriptedRawResponse);
    }
}
