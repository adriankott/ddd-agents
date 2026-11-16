<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\ConversationPurge;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The ordered statement list of one batch of the evaluation purge ({@see AIConversationEvalPurgeStep}), run in the
 * order of the set. Each step is held under the key of its own object: two steps on the same table and column stay
 * two steps.
 *
 * @property AIConversationEvalPurgeStep[] $elements
 * @method AIConversationEvalPurgeStep[] getElements()
 * @method AIConversationEvalPurgeStep|null first()
 * @method AIConversationEvalPurgeStep|null last()
 * @method AIConversationEvalPurgeStep|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AIConversationEvalPurgeSteps extends ObjectSet
{
    /**
     * A new set with the steps of this set in the order of `$stepKeysInOrder` ({@see AIConversationEvalPurgeStep::getStepKey()}):
     * the steps of each listed key at its position (steps sharing a key keep their order), then every step whose key
     * the list does not name, in their order in this set. A listed key without a step is passed over. This set is not
     * changed.
     *
     * @param string[] $stepKeysInOrder
     */
    public function buildOrderedByStepKeys(array $stepKeysInOrder): static
    {
        $orderedSteps = static::newInstance();
        $stepsByStepKey = [];
        foreach ($this->getElements() as $step) {
            $stepsByStepKey[$step->getStepKey()][] = $step;
        }
        foreach (array_values(array_unique($stepKeysInOrder)) as $stepKey) {
            foreach ($stepsByStepKey[$stepKey] ?? [] as $step) {
                $orderedSteps->add($step);
            }
            unset($stepsByStepKey[$stepKey]);
        }
        foreach ($this->getElements() as $step) {
            if (isset($stepsByStepKey[$step->getStepKey()])) {
                $orderedSteps->add($step);
            }
        }
        return $orderedSteps;
    }

    /** A new set with the steps of this set followed by the steps of `$otherSteps`; neither set is changed. */
    public function buildMergedWith(AIConversationEvalPurgeSteps $otherSteps): static
    {
        $mergedSteps = static::newInstance();
        foreach ($this->getElements() as $step) {
            $mergedSteps->add($step);
        }
        foreach ($otherSteps->getElements() as $step) {
            $mergedSteps->add($step);
        }
        return $mergedSteps;
    }

    /** @return string[] the keys of the steps, in the order of the set */
    public function getStepKeys(): array
    {
        $stepKeys = [];
        foreach ($this->getElements() as $step) {
            $stepKeys[] = $step->getStepKey();
        }
        return $stepKeys;
    }

    /** @return string[] the report labels of the steps, in the order of the set */
    public function getReportLabels(): array
    {
        $reportLabels = [];
        foreach ($this->getElements() as $step) {
            $reportLabels[] = $step->getReportLabel();
        }
        return $reportLabels;
    }

    /** @return string[] the tables the steps write, each once, in the order of their first step */
    public function getTableNames(): array
    {
        $tableNames = [];
        foreach ($this->getElements() as $step) {
            $tableNames[] = $step->tableName;
        }
        return array_values(array_unique($tableNames));
    }

    /** The first step with this key ({@see AIConversationEvalPurgeStep::getStepKey()}), or null. */
    public function findStepByStepKey(string $stepKey): ?AIConversationEvalPurgeStep
    {
        foreach ($this->getElements() as $step) {
            if ($step->getStepKey() === $stepKey) {
                return $step;
            }
        }
        return null;
    }
}
