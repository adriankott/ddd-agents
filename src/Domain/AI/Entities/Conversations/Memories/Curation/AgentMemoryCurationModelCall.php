<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Memories\Curation;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One model call of the memory curation as it went over the wire: the stage, the model that ran, the request body as
 * sent, the model's answer (its text and the provider's whole answer, decoded), the tokens and which attempt of the
 * call it was (a call whose answer had the wrong shape is asked once more; both attempts are recorded). An application
 * that evaluates its curator reads a failed check of what the curation stored against exactly this record.
 *
 * Only the request BODY is kept. The request headers carry the egress authentication (the provider's key) and are
 * never copied: {@see takeRequestBodyOfPayload()} takes the `body` of a payload and nothing else.
 */
class AgentMemoryCurationModelCall extends ValueObject
{
    /** @var string The extraction of candidate memories from the owner's new messages */
    public const string STAGE_EXTRACTION = 'EXTRACTION';

    /** @var string The reconciliation of the candidates against the stored neighbours (the default prompt) */
    public const string STAGE_RECONCILIATION = 'RECONCILIATION';

    /** @var string The reconciliation of the candidates that settle a raised contradiction (the resolution prompt) */
    public const string STAGE_CONFLICT_RESOLUTION = 'CONFLICT_RESOLUTION';

    /** @var string|null One of the STAGE_* constants */
    public ?string $stage = null;

    /** @var string|null The name of the model that ran (after a fallback: the fallback model) */
    public ?string $aiModelName = null;

    /** @var array|null The request body as sent (model, messages, response format …); null when no body was built */
    public ?array $requestBody = null;

    /** @var string|null The text the model answered, before parsing; null when the model gave no text */
    public ?string $rawResultText = null;

    /** @var array|null The provider's whole answer, decoded; null when nothing came back */
    public ?array $rawResponse = null;

    /** @var int|null The input tokens of the call, as the provider reported them */
    public ?int $inputTokens = null;

    /** @var int|null The output tokens of the call, as the provider reported them */
    public ?int $outputTokens = null;

    /** @var int Which attempt of the call this was: 1, or 2 for the repetition after an answer of the wrong shape */
    public int $attempt = 1;

    /**
     * Keeps the `body` of a request payload (the shape `['body' => …, 'headers' => …]` the language-model trait
     * captures). The headers are never read.
     *
     * @param array|null $requestPayload
     * @return void
     */
    public function takeRequestBodyOfPayload(?array $requestPayload): void
    {
        $requestBody = $requestPayload['body'] ?? null;
        $this->requestBody = is_array($requestBody) ? $requestBody : null;
    }

    /**
     * Keeps the provider's answer object as a decoded array.
     *
     * @param object|null $rawResponseObject
     * @return void
     */
    public function takeRawResponse(?object $rawResponseObject): void
    {
        if ($rawResponseObject === null) {
            $this->rawResponse = null;
            return;
        }
        $decodedRawResponse = json_decode(
            (string)json_encode($rawResponseObject, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            true
        );
        $this->rawResponse = is_array($decodedRawResponse) ? $decodedRawResponse : null;
    }

    /**
     * Whether this attempt repeated a call whose earlier answer had the wrong shape.
     *
     * @return bool
     */
    public function isRepeatedAttempt(): bool
    {
        return $this->attempt > 1;
    }

    /**
     * Keyed by object: two attempts of one call may carry identical content (the same body, the same answer) and are
     * both kept.
     *
     * @return string
     */
    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)spl_object_id($this));
    }
}
