<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The structure of the owner report one run of an evaluation schedule wrote: its headings and widget types in reading
 * order ({@see \DDD\Domain\AI\Entities\Conversations\Reports\AIConversationOwnerReport::buildStructureLines()}); no
 * line for a run without a model-written report.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalScheduleRunStructure extends ValueObject
{
    /** @var int The number of the run, from 1 */
    public int $runNumber = 1;

    /** @var string[] The structure lines of the run's report */
    public array $structureLines = [];

    /**
     * The structure of the run number `$runNumber`.
     *
     * @param int $runNumber
     * @param string[] $structureLines
     * @return static
     */
    public static function forRun(int $runNumber, array $structureLines): static
    {
        $agentEvalScheduleRunStructure = static::newInstance();
        $agentEvalScheduleRunStructure->runNumber = $runNumber;
        $agentEvalScheduleRunStructure->structureLines = array_values($structureLines);
        return $agentEvalScheduleRunStructure;
    }
}
