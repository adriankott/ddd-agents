<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval\Probes;

use DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeHttpAnswer;
use DDD\Domain\AI\Entities\ModelCalls\ModelCallSettings;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Sends the requests of `app:ai-agent:probe-reasoning-replay` to OpenRouter with the agent's own credential: the
 * key named by `AI.modelCalls.openRouterApiKeyEnvName` as the `Authorization: Bearer` header, as the agent's direct
 * egress does. Only the two fixed OpenRouter endpoints are called; a refused request is returned, never thrown.
 * Tests replace {@see createHttpClient()}.
 */
class AgentReasoningReplayProbeTransportService extends Service
{
    public const string CHAT_COMPLETIONS_URL = 'https://openrouter.ai/api/v1/chat/completions';

    public const string RESPONSES_URL = 'https://openrouter.ai/api/v1/responses';

    /** The Anthropic Messages API on OpenRouter */
    public const string MESSAGES_URL = 'https://openrouter.ai/api/v1/messages';

    public const array ENDPOINT_URLS = [self::CHAT_COMPLETIONS_URL, self::RESPONSES_URL, self::MESSAGES_URL];

    /**
     * POST one JSON body.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $additionalHeaders the headers of the wire API next to the authentication (the Messages API: version and betas)
     * @throws InternalErrorException The endpoint is not one of ENDPOINT_URLS, or the key is missing
     */
    public function postJson(string $url, array $body, int $timeoutSeconds, array $additionalHeaders = []): ReasoningReplayProbeHttpAnswer
    {
        if (!in_array($url, self::ENDPOINT_URLS, true)) {
            throw new InternalErrorException('The probe calls only the OpenRouter chat completions, responses and messages endpoints, not ' . $url);
        }
        $reasoningReplayProbeHttpAnswer = new ReasoningReplayProbeHttpAnswer();
        $startedAt = hrtime(true);
        try {
            $response = $this->createHttpClient()->request('POST', $url, [
                'headers' => $additionalHeaders + ['Authorization' => 'Bearer ' . $this->findOpenRouterKey(), 'Content-Type' => 'application/json'],
                'body' => (string)json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'timeout' => $timeoutSeconds,
                'max_duration' => $timeoutSeconds,
            ]);
            $reasoningReplayProbeHttpAnswer->statusCode = $response->getStatusCode();
            $reasoningReplayProbeHttpAnswer->rawBody = $response->getContent(false);
        } catch (Throwable $throwable) {
            $reasoningReplayProbeHttpAnswer->transportError = mb_substr($throwable->getMessage(), 0, 500);
        }
        $reasoningReplayProbeHttpAnswer->durationMs = (int)round((hrtime(true) - $startedAt) / 1e6);
        return $reasoningReplayProbeHttpAnswer;
    }

    /**
     * @throws InternalErrorException
     */
    protected function findOpenRouterKey(): string
    {
        $openRouterApiKeyEnvName = ModelCallSettings::getSettingsOfThisProcess()->getOpenRouterApiKeyEnvName();
        $openRouterKey = $openRouterApiKeyEnvName !== null ? Config::getEnv($openRouterApiKeyEnvName) : null;
        if (!is_string($openRouterKey) || $openRouterKey === '') {
            throw new InternalErrorException(
                'The probe needs the OpenRouter key of the agent: the ' . ($openRouterApiKeyEnvName ?? 'AI.modelCalls.openRouterApiKeyEnvName') . ' env var is missing.'
            );
        }
        return $openRouterKey;
    }

    protected function createHttpClient(): HttpClientInterface
    {
        return HttpClient::create();
    }
}
