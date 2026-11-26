<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Doubles;

use DDD\Domain\AI\Services\Voice\Live\OpenAILiveVoiceProviderAdapter;

/** A GPT-Live adapter with a fixed model and host and no configuration read. */
class FixedModelOpenAILiveVoiceProviderAdapter extends OpenAILiveVoiceProviderAdapter
{
    public function __construct(protected string $fixedLiveModel = 'gpt-live-1.1', protected string $fixedApiHost = 'api.openai.com')
    {
    }

    public function getLiveModel(): string
    {
        return $this->fixedLiveModel;
    }

    public function buildSidebandWebSocketUrl(string $providerSessionId): string
    {
        return 'wss://' . $this->fixedApiHost . static::SESSIONS_PATH . '/' . rawurlencode($providerSessionId) . '/attach';
    }
}
