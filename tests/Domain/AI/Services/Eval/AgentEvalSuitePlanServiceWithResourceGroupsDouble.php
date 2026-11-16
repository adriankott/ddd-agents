<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalDurationPriors;
use DDD\Domain\AI\Entities\Evals\AgentEvalFixtures;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuitePlanOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTasks;
use DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService;

/**
 * The suite planning of an application that groups its fixtures into resource groups, runs the writing cases of one
 * fixture alone on their agent space, and plans a kind of task of its own ({@see KIND_WAVED_CASE}: every case of a
 * fixture runs on a throwaway scope of its own, two cases per wave).
 */
class AgentEvalSuitePlanServiceWithResourceGroupsDouble extends AgentEvalSuitePlanService
{
    /** @var string The application's own kind */
    public const string KIND_WAVED_CASE = 'wavedCase';

    /** @var string[] Fixture name → resource group */
    public array $resourceGroupByFixture = [
        'Notes.Profile' => 'profileAndListings',
        'Notes.Listings' => 'profileAndListings',
        'Notes.Settings' => 'replies',
        'Notes.Replies' => 'replies',
    ];

    /** @var string[] The exclusive fixtures */
    public array $exclusiveFixtureNames = ['Assistant.Grants', 'Combined'];

    public function getResourceGroupByFixture(): array
    {
        return $this->resourceGroupByFixture;
    }

    public function getExclusiveFixtureNames(): array
    {
        return $this->exclusiveFixtureNames;
    }

    public function getDurationPriors(): AgentEvalDurationPriors
    {
        $agentEvalDurationPriors = parent::getDurationPriors();
        $agentEvalDurationPriors->addPrior(self::KIND_WAVED_CASE, AgentEvalSuiteTask::FLAVOR_JUDGE, 480.0);
        return $agentEvalDurationPriors;
    }

    public function buildTasksOfApplicationKind(string $kind, string $suite, AgentEvalFixtures $agentEvalFixtures, AgentEvalSuitePlanOptions $agentEvalSuitePlanOptions): AgentEvalSuiteTasks
    {
        $agentEvalSuiteTasks = AgentEvalSuiteTasks::newInstance();
        if ($kind !== self::KIND_WAVED_CASE) {
            return $agentEvalSuiteTasks;
        }
        $tasksPlanned = 0;
        foreach ($agentEvalFixtures->getElements() as $agentEvalFixture) {
            foreach ($agentEvalFixture->getCases()->getElements() as $agentEvalCase) {
                $agentEvalSuiteTask = AgentEvalSuiteTask::newInstance();
                $agentEvalSuiteTask->kind = self::KIND_WAVED_CASE;
                $agentEvalSuiteTask->suite = $suite;
                $agentEvalSuiteTask->caseId = $agentEvalCase->getId();
                $agentEvalSuiteTask->flavor = AgentEvalSuiteTask::FLAVOR_JUDGE;
                $agentEvalSuiteTask->access = AgentEvalSuiteTask::ACCESS_NONE;
                $agentEvalSuiteTask->waveGroup = 'waved';
                $agentEvalSuiteTask->waveIndex = intdiv($tasksPlanned++, 2);
                $agentEvalSuiteTask->taskId = $agentEvalSuiteTask->getRunKey() . '|' . $suite . '|' . $agentEvalSuiteTask->caseId;
                $agentEvalSuiteTask->estimatedSeconds = $this->estimateSecondsOfTask($agentEvalSuiteTask);
                $agentEvalSuiteTasks->add($agentEvalSuiteTask);
            }
        }
        return $agentEvalSuiteTasks;
    }
}
