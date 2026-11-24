<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\Probes;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The verdict of one scenario of `app:agent:probe-reasoning-replay`: its calls and whether what happened is what
 * the scenario expects (PASS), a fact the probe exists to find out (FINDING), or a failure of the probe's own path
 * (FAIL — the command then exits 1).
 */
class ReasoningReplayProbeResult extends ValueObject
{
    /** The scenario behaved as its expectation says */
    public const string VERDICT_PASS = 'PASS';

    /** A refused replay after an edit, a forced choice the model rejects: the evidence the probe is run for */
    public const string VERDICT_FINDING = 'FINDING';

    /** The probe's own path broke (transport, no tool call, no reasoning to replay, a refused clean replay) */
    public const string VERDICT_FAIL = 'FAIL';

    /** @var string One of AgentReasoningReplayProbeService::SCENARIOS */
    public string $scenario = '';

    /** @var string `chatCompletions` or `responses` */
    public string $wireApi = '';

    /** @var string The model (catalog name) */
    public string $modelName = '';

    /** @var string|null The model of the second call in the model-switch scenario */
    public ?string $switchModelName = null;

    /** @var bool Whether the replaying calls asked to drop mismatching thinking blocks (`thinking.block_binding`) */
    public bool $requestsDropBlock = false;

    /** @var bool Whether the probe sent a non-OpenAI model on the Responses API, which the agent refuses at runtime */
    public bool $bypassesResponsesRejection = false;

    /** @var string What the scenario expects */
    public string $expectation = '';

    /** @var string VERDICT_* */
    public string $verdict = self::VERDICT_FAIL;

    /** @var string Why the verdict is what it is */
    public string $verdictReason = '';

    public ReasoningReplayProbeCalls $calls;

    public function __construct()
    {
        $this->calls = new ReasoningReplayProbeCalls();
        parent::__construct();
    }

    public function isFailure(): bool
    {
        return $this->verdict === self::VERDICT_FAIL;
    }

    /** The summed cost of the scenario's calls in USD (null when OpenRouter reported none) */
    public function getCostInUsd(): ?float
    {
        $costInUsd = null;
        foreach ($this->calls->getElements() as $reasoningReplayProbeCall) {
            if ($reasoningReplayProbeCall->costInUsd !== null) {
                $costInUsd = ($costInUsd ?? 0.0) + $reasoningReplayProbeCall->costInUsd;
            }
        }
        return $costInUsd;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
