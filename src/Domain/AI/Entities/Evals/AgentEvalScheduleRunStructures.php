<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The report structures of the runs of one evaluation schedule ({@see AgentEvalScheduleRunStructure}), in the order
 * the runs were fired, and the verdict over them: how many later runs kept the structure of the run right before them —
 * the report a run reads with the earlier-reports tool (a section added because the data demanded it counts as a
 * change too).
 *
 * @property AgentEvalScheduleRunStructure[] $elements
 * @method AgentEvalScheduleRunStructure[] getElements()
 * @method AgentEvalScheduleRunStructure|null first()
 * @phpstan-consistent-constructor
 */
class AgentEvalScheduleRunStructures extends ObjectSet
{
    /**
     * The structure of the run number `$runNumber`; null when that run has none recorded.
     *
     * @param int $runNumber
     * @return AgentEvalScheduleRunStructure|null
     */
    public function findStructureOfRun(int $runNumber): ?AgentEvalScheduleRunStructure
    {
        foreach ($this->getElements() as $agentEvalScheduleRunStructure) {
            if ($agentEvalScheduleRunStructure->runNumber === $runNumber) {
                return $agentEvalScheduleRunStructure;
            }
        }
        return null;
    }

    /**
     * The numbers of the runs after the first, in order.
     *
     * @return int[]
     */
    public function getLaterRunNumbers(): array
    {
        $laterRunNumbers = [];
        foreach ($this->getElements() as $agentEvalScheduleRunStructure) {
            if ($agentEvalScheduleRunStructure->runNumber > 1) {
                $laterRunNumbers[] = $agentEvalScheduleRunStructure->runNumber;
            }
        }
        return $laterRunNumbers;
    }

    /**
     * The numbers of the later runs whose structure equals the structure of the run right before them, in order.
     *
     * @return int[]
     */
    public function getRunNumbersThatKeptTheStructure(): array
    {
        $keptStructureRunNumbers = [];
        foreach ($this->getLaterRunNumbers() as $laterRunNumber) {
            $structureOfRunBefore = $this->findStructureOfRun($laterRunNumber - 1);
            if ($structureOfRunBefore !== null && $this->findStructureOfRun($laterRunNumber)?->structureLines === $structureOfRunBefore->structureLines) {
                $keptStructureRunNumbers[] = $laterRunNumber;
            }
        }
        return $keptStructureRunNumbers;
    }

    /**
     * The verdict line: how many later runs kept the structure of the run before them, and which.
     *
     * @return string
     */
    public function buildVerdictLine(): string
    {
        $laterRunNumbers = $this->getLaterRunNumbers();
        if ($laterRunNumbers === []) {
            return 'Verdict: one run only — no structure to compare.';
        }
        $keptStructureRunNumbers = $this->getRunNumbersThatKeptTheStructure();
        return sprintf(
            'Verdict: %d of %d later runs kept the structure of the run before them (runs %s).',
            count($keptStructureRunNumbers),
            count($laterRunNumbers),
            $keptStructureRunNumbers === [] ? '—' : implode(', ', $keptStructureRunNumbers)
        );
    }
}
