<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRowHeader;
use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;
use DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService;
use DDD\Infrastructure\Services\DDDService;

/**
 * What the evaluation commands that run the cases of one fixture share: the key under which a case names its scope,
 * the fixture file read from its path, and the services they call.
 *
 * The scope of a case: the module's cases name it in `agentSpaceId`; an application whose cases and rows name it with a
 * key of its own declares that key on its same-path {@see AgentEvalCaseRowHeader} (`SCOPE_FIELD_NAME`), the key its
 * case rows carry. A case names its scope when it carries one of both keys with a value.
 */
trait AgentEvalCaseScopeAndFixtureTrait
{
    /**
     * The keys under which a case names its scope: the application's row key ({@see AgentEvalCaseRowHeader::SCOPE_FIELD_NAME}
     * of the header class in force), then the module's `agentSpaceId`; one key when both are the same.
     *
     * @return string[]
     */
    protected function getCaseScopeFieldNames(): array
    {
        $agentEvalCaseRowHeader = AgentEvalCaseRowHeader::withoutFields();
        return array_values(array_unique([$agentEvalCaseRowHeader::SCOPE_FIELD_NAME, AgentEvalCase::KEY_AGENT_SPACE_ID]));
    }

    /**
     * The scope value the case names itself: the value of the first scope key it carries with a value, as a whole
     * number; null when it names none.
     */
    protected function findScopeValueNamedByCase(AgentEvalCase $agentEvalCase): ?int
    {
        foreach ($this->getCaseScopeFieldNames() as $scopeFieldName) {
            $scopeValue = $agentEvalCase->getApplicationValue($scopeFieldName);
            if ($scopeValue !== null) {
                return (int)$scopeValue;
            }
        }
        return null;
    }

    /**
     * The scope keys the case carries, with their values as written (the part of the case a run needs to find its
     * scope when it runs a reduced copy of the case).
     *
     * @return array<string, string|int|null> scope key → value
     */
    protected function buildScopeFieldsOfCase(AgentEvalCase $agentEvalCase): array
    {
        return array_intersect_key($agentEvalCase->toArray(), array_flip($this->getCaseScopeFieldNames()));
    }

    /** The runner of evaluated cases (the application's class, when it replaces it). */
    protected function getAgentEvalRunnerService(): AgentEvalRunnerService
    {
        /** @var AgentEvalRunnerService $agentEvalRunnerService */
        $agentEvalRunnerService = DDDService::instance()->getService(AgentEvalRunnerService::class);
        return $agentEvalRunnerService;
    }

    /** The planning service of the suite: the fixture paths of the configuration (the application's class, when it replaces it). */
    protected function getAgentEvalSuitePlanService(): AgentEvalSuitePlanService
    {
        /** @var AgentEvalSuitePlanService $agentEvalSuitePlanService */
        $agentEvalSuitePlanService = DDDService::instance()->getService(AgentEvalSuitePlanService::class);
        return $agentEvalSuitePlanService;
    }

    /** The service of the result files (the application's class, when it replaces it). */
    protected function getAgentEvalResultFilesService(): AgentEvalResultFilesService
    {
        /** @var AgentEvalResultFilesService $agentEvalResultFilesService */
        $agentEvalResultFilesService = DDDService::instance()->getService(AgentEvalResultFilesService::class);
        return $agentEvalResultFilesService;
    }
}
