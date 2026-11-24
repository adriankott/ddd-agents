<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Wire;

/**
 * One change the provider made to a Messages request before the model read it, as the answer's
 * `input_transformations` list reports it ({@see AgentMessagesResponse::getInputTransformations()}).
 */
class AgentMessagesInputTransformation
{
    /** @var string What happened: `thinking_dropped`, or a type a later provider version adds. */
    public string $type = '';

    /** @var string The block's place in the request: `messages.<message position>.content.<block position>`. */
    public string $path = '';

    /** @var string Why: `prefix_binding_mismatch`, `model_binding_mismatch`, or a reason a later provider version adds. */
    public string $reason = '';

    public function isDroppedThinking(): bool
    {
        return $this->type === AgentMessagesResponse::TRANSFORMATION_THINKING_DROPPED;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'path' => $this->path, 'reason' => $this->reason];
    }
}
