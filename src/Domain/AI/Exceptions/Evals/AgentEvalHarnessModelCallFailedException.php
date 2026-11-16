<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Evals;

use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use RuntimeException;

/**
 * A model call of the evaluation harness — the judge's or the simulated owner's (the driver's) — that the provider
 * refused or that failed: the load of the completion never throws and answers an empty text, the reason is the load's
 * failure reason (the transport's rejection, the upstream HTTP status and the provider's message).
 *
 * The message names the role of the call, the model and the reason (`judge call failed: <model>: <reason>`,
 * `driver call failed: <model>: <reason>`), so an error row of the case carries the provider's message. A failed call is
 * an evaluation error, never a verdict on the agent: the judge's turns into an ERROR verdict, the driver's ends the
 * case as an error row.
 */
class AgentEvalHarnessModelCallFailedException extends RuntimeException
{
    /** @var string The start of the message of a failed judge call */
    public const string JUDGE_CALL_FAILED_PREFIX = 'judge call failed: ';

    /** @var string The start of the message of a failed driver call */
    public const string DRIVER_CALL_FAILED_PREFIX = 'driver call failed: ';

    /** @var string The start of the message of a failed harness call of another usage scope */
    public const string HARNESS_CALL_FAILED_PREFIX = 'harness model call failed: ';

    /** @var string The model of the failed call */
    protected string $aiModelName;

    /** @var string The usage scope of the failed call */
    protected string $aiUsageScope;

    /** @var string Why the call failed, as the load reported it */
    protected string $failureReason;

    /**
     * @param string $aiModelName the model of the failed call
     * @param string $aiUsageScope the usage scope of the call ({@see AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION},
     *                             {@see AgentAIUsageScope::AI_AGENT_SIMULATE_OWNER})
     * @param string $failureReason why the call failed, as the load reported it
     */
    public function __construct(string $aiModelName, string $aiUsageScope, string $failureReason)
    {
        parent::__construct(static::getMessagePrefixOfUsageScope($aiUsageScope) . $aiModelName . ': ' . $failureReason);
        $this->aiModelName = $aiModelName;
        $this->aiUsageScope = $aiUsageScope;
        $this->failureReason = $failureReason;
    }

    /** The start of the message for a call booked under `$aiUsageScope`: the role of the call. */
    public static function getMessagePrefixOfUsageScope(string $aiUsageScope): string
    {
        return match ($aiUsageScope) {
            AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION => static::JUDGE_CALL_FAILED_PREFIX,
            AgentAIUsageScope::AI_AGENT_SIMULATE_OWNER => static::DRIVER_CALL_FAILED_PREFIX,
            default => static::HARNESS_CALL_FAILED_PREFIX,
        };
    }

    /** The model of the failed call. */
    public function getAiModelName(): string
    {
        return $this->aiModelName;
    }

    /** The usage scope of the failed call. */
    public function getAiUsageScope(): string
    {
        return $this->aiUsageScope;
    }

    /** Why the call failed, as the load reported it (e.g. `http 404 from the upstream call: <provider message>`). */
    public function getFailureReason(): string
    {
        return $this->failureReason;
    }
}
