<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\Probes;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\Base\Entities\ValueObject;

/** The settings every call of one probe scenario shares. */
class ReasoningReplayProbeRun extends ValueObject
{
    /** The model of the scenario's calls (the model-switch scenario sends its second call elsewhere) */
    public AIModel $aiModel;

    /** @var string `chatCompletions` or `responses` */
    public string $wireApi = '';

    /** @var bool Whether replaying calls ask to drop mismatching thinking blocks */
    public bool $requestsDropBlock = false;

    /** @var string The reasoning effort of every call */
    public string $reasoningEffort = 'medium';

    /** @var int The total timeout of one call in seconds */
    public int $timeoutSeconds = 120;
}
