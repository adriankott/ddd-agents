<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Evals;

use InvalidArgumentException;

/**
 * A judge or driver model name of an evaluation that the model catalog does not know: neither a model of the catalog
 * (`AI.models`) nor a retired name of one (`AIModel::RETIRED_MODEL_SUCCESSORS`). Without the refusal the model call
 * would run on the agent's default model and the verdict would be given by another model than the one named.
 *
 * Thrown before any model call ({@see \DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService::refuseUnknownModelName()});
 * the evaluation commands answer it with their invalid-input exit code.
 */
class AgentEvalUnknownModelNameException extends InvalidArgumentException
{
    /** @var string The model name the catalog does not know */
    protected string $unknownModelName;

    /**
     * @param string $unknownModelName the model name the catalog does not know
     * @param string $message the refusal, naming the model and where the known names come from
     */
    public function __construct(string $unknownModelName, string $message)
    {
        parent::__construct($message);
        $this->unknownModelName = $unknownModelName;
    }

    /** The model name the catalog does not know. */
    public function getUnknownModelName(): string
    {
        return $this->unknownModelName;
    }
}
