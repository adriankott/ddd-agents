<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\Probes;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One model call of a reasoning-replay probe: whether the router accepted it, what reasoning came back, what the call
 * cost and how much of its input came from the prompt cache.
 */
class ReasoningReplayProbeCall extends ValueObject
{
    /** @var string What the call does in its scenario (`call 1: tools`, `call 2: replay after edit`, …) */
    public string $label = '';

    /** @var string The model of the call (catalog name) */
    public string $modelName = '';

    /** @var int|null The HTTP status; null when the transport failed */
    public ?int $httpStatus = null;

    /** @var bool Whether the call was answered without an error */
    public bool $isAccepted = false;

    /** @var string|null The error text of a refused call (the provider's message, or the transport error), truncated */
    public ?string $errorText = null;

    /** @var string|null The provider OpenRouter routed the call to (the answer's `provider`) */
    public ?string $provider = null;

    /** @var int How many reasoning items came back (`reasoning_details` / Responses `reasoning` output items) */
    public int $reasoningItemCount = 0;

    /** @var string[] The types of the reasoning items that came back, in order */
    public array $reasoningItemTypes = [];

    /** @var bool Whether a returned reasoning item carries a `signature` or `encrypted_content` (what a replay must send back) */
    public bool $hasSignedReasoning = false;

    /** @var int How many reasoning items this call sent back from an earlier call */
    public int $replayedReasoningItemCount = 0;

    /** @var string[] The names of the tools the model called */
    public array $calledToolNames = [];

    /** @var string|null The tool choice the call sent (`auto`, `required`, `none` or the JSON of a named pin) */
    public ?string $sentToolChoice = null;

    /** @var int|null Input tokens */
    public ?int $inputTokens = null;

    /** @var int|null Input tokens read from the prompt cache */
    public ?int $cachedInputTokens = null;

    /** @var int|null Input tokens written to the prompt cache */
    public ?int $cacheWriteTokens = null;

    /** @var int|null Output tokens (reasoning included) */
    public ?int $outputTokens = null;

    /** @var float|null The cost OpenRouter reports for the call, in USD */
    public ?float $costInUsd = null;

    /** @var string|null The answer's `input_transformations` (dropped thinking blocks) as JSON; null when absent */
    public ?string $inputTransformationsJson = null;

    /** @var int How many thinking blocks the provider dropped from this call's request (`thinking_dropped` entries of `input_transformations`) */
    public int $droppedThinkingBlockCount = 0;

    /** @var int|null The reasoning (thinking) tokens the provider reports for the answer; null when it names none */
    public ?int $reasoningTokens = null;

    /** @var int Wall time of the call in milliseconds */
    public int $durationMs = 0;

    /** The share of the input read from the prompt cache (0..1); null without usage */
    public function getCacheShare(): ?float
    {
        if ($this->inputTokens === null || $this->inputTokens <= 0 || $this->cachedInputTokens === null) {
            return null;
        }
        return round($this->cachedInputTokens / $this->inputTokens, 3);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic((string)spl_object_id($this));
    }
}
