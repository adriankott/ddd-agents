<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Admission;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\AIModelsService;
use RuntimeException;

/**
 * The model catalog of the AI package without its configuration: records each model name asked for and knows none;
 * throws a RuntimeException with {@see $failureMessage} when one is set.
 */
class RecordingAIModelsService extends AIModelsService
{
    /** @var string[] The model names asked for, in order */
    public array $askedModelNames = [];

    public ?string $failureMessage = null;

    public function getAIModelByName(string $aiModelName): ?AIModel
    {
        $this->askedModelNames[] = $aiModelName;
        if ($this->failureMessage !== null) {
            throw new RuntimeException($this->failureMessage);
        }

        return null;
    }
}
