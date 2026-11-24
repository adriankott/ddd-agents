<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval\Probes;

use DDD\Domain\AI\Entities\Evals\Probes\ReasoningReplayProbeHttpAnswer;
use DDD\Domain\AI\Services\Eval\Probes\AgentReasoningReplayProbeTransportService;

/** Answers the probe's requests from a script and keeps every body it was sent. */
class ScriptedReasoningReplayProbeTransportService extends AgentReasoningReplayProbeTransportService
{
    /** @var ReasoningReplayProbeHttpAnswer[] The answers, in order */
    public array $scriptedAnswers = [];

    /** @var array<int, array<string, mixed>> The bodies sent, in order */
    public array $sentBodies = [];

    /** @var string[] The URLs called, in order */
    public array $calledUrls = [];

    public function postJson(string $url, array $body, int $timeoutSeconds): ReasoningReplayProbeHttpAnswer
    {
        $this->calledUrls[] = $url;
        $this->sentBodies[] = $body;
        return array_shift($this->scriptedAnswers) ?? self::answer(500, '{"error":{"message":"no scripted answer"}}');
    }

    public static function answer(?int $statusCode, string $rawBody, ?string $transportError = null): ReasoningReplayProbeHttpAnswer
    {
        $reasoningReplayProbeHttpAnswer = new ReasoningReplayProbeHttpAnswer();
        $reasoningReplayProbeHttpAnswer->statusCode = $statusCode;
        $reasoningReplayProbeHttpAnswer->rawBody = $rawBody;
        $reasoningReplayProbeHttpAnswer->transportError = $transportError;
        return $reasoningReplayProbeHttpAnswer;
    }
}
