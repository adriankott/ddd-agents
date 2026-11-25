<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanDoneCriterionVerdict;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterion;
use DDD\Domain\AI\Entities\Conversations\Purposes\DoneCriteria\TaskPlanDoneCriterionInputs;

/**
 * A criterion that reads the plan it judges: it answers nothing while its own leaf is open and confirms the leaf once
 * the leaf is done — the record read AFTER an applied tick, which only such a criterion can answer.
 */
class LeafReadingItsOwnStatusDoneCriterion extends TaskPlanDoneCriterion
{
    public const string KEY = 'leaf.readsItsOwnStatus';

    public function resolve(TaskPlanDoneCriterionInputs $taskPlanDoneCriterionInputs): ?TaskPlanDoneCriterionVerdict
    {
        $ownLeaf = $taskPlanDoneCriterionInputs->taskPlanItems?->getLeafByDoneCriterion(self::KEY);
        return $ownLeaf?->status === TaskPlanItem::STATUS_DONE ? $this->done('the plan shows the round closed') : null;
    }
}
