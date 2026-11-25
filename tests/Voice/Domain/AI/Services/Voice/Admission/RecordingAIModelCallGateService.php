<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Admission;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Services\AIModelCallGateService;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use Throwable;

/**
 * The model-call gate of ddd-ai recording each stage it is asked, in order, with the model call; the stage named in
 * {@see $throwingStageName} throws {@see $stageException}. {@see $checksBudget} is the answer of
 * `needsCostEstimateForBudgetCheck()`.
 */
class RecordingAIModelCallGateService extends AIModelCallGateService
{
    /** @var string[] */
    public array $askedStageNames = [];

    /** @var AIModelCall[] */
    public array $askedModelCalls = [];

    public ?MoneyAmount $estimatedCosts = null;

    public bool $checksBudget = false;

    public ?string $throwingStageName = null;

    public ?Throwable $stageException = null;

    public function refuseModelCallWithoutUsageScopeOrEnvelope(AIModelCall $aiModelCall): void
    {
        $this->askStage('refuseModelCallWithoutUsageScopeOrEnvelope', $aiModelCall);
    }

    public function refuseModelCallForIneligibleBilledScope(AIModelCall $aiModelCall): void
    {
        $this->askStage('refuseModelCallForIneligibleBilledScope', $aiModelCall);
    }

    public function needsCostEstimateForBudgetCheck(AIModelCall $aiModelCall): bool
    {
        $this->askStage('needsCostEstimateForBudgetCheck', $aiModelCall);

        return $this->checksBudget;
    }

    public function refuseModelCallWithoutBilledAccount(AIModelCall $aiModelCall): void
    {
        $this->askStage('refuseModelCallWithoutBilledAccount', $aiModelCall);
    }

    public function loadBudgetSettingsOfBilledAccount(AIModelCall $aiModelCall): void
    {
        $this->askStage('loadBudgetSettingsOfBilledAccount', $aiModelCall);
    }

    public function refuseModelCallOverBudget(AIModelCall $aiModelCall, ?MoneyAmount $estimatedCosts): void
    {
        $this->estimatedCosts = $estimatedCosts;
        $this->askStage('refuseModelCallOverBudget', $aiModelCall);
    }

    protected function askStage(string $stageName, AIModelCall $aiModelCall): void
    {
        $this->askedStageNames[] = $stageName;
        $this->askedModelCalls[] = $aiModelCall;
        if ($stageName === $this->throwingStageName && $this->stageException !== null) {
            throw $this->stageException;
        }
    }
}
