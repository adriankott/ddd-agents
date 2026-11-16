<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteCases\AgentEvalSuiteCase;
use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteCases\AgentEvalSuiteCases;
use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteRun;
use DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\AgentEvalSuiteCases\DBAgentEvalSuiteCase;
use DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\AgentEvalSuiteCases\DBAgentEvalSuiteCases;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * The cases of recorded evaluation runs ({@see AgentEvalSuiteCase}): read one run's cases page by page, optionally
 * only some verdicts. Written only by {@see AgentEvalSuiteRunsService::recordSuiteRun()}.
 *
 * The reads set the default query options of the case set the container resolves — an application's same-path
 * subclass of {@see AgentEvalSuiteCases} — because its set repository reads the options of its own class.
 *
 * @method AgentEvalSuiteCase|null find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentEvalSuiteCases|null findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBAgentEvalSuiteCase getEntityRepoClassInstance()
 * @method DBAgentEvalSuiteCases getEntitySetRepoClassInstance()
 */
class AgentEvalSuiteCasesService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentEvalSuiteCase::class;

    /**
     * One page of a run's cases in the order they were recorded, optionally only the given verdicts.
     *
     * @param AgentEvalSuiteRun $agentEvalSuiteRun
     * @param string[]|null $verdicts
     * @param int $top
     * @param int $skip
     * @return AgentEvalSuiteCases
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCasesOfSuiteRun(AgentEvalSuiteRun $agentEvalSuiteRun, ?array $verdicts, int $top, int $skip): AgentEvalSuiteCases
    {
        $agentEvalSuiteCasesClass = $this->getAgentEvalSuiteCasesClass();
        $agentEvalSuiteCasesClass::setDefaultQueryOptionsSnapshot();
        try {
            $caseFilters = $this->buildFiltersForSuiteRun($agentEvalSuiteRun, $verdicts);
            $caseOrderBy = OrderByOptions::fromString('id asc');
            $agentEvalSuiteCasesClass::getDefaultQueryOptions()
                ->setFilters($caseFilters)
                ->setOrderBy($caseOrderBy)
                ->setTop($top)
                ->setSkip($skip);
            return $this->findAll() ?? AgentEvalSuiteCases::newInstance();
        } finally {
            $agentEvalSuiteCasesClass::restoreDefaultQueryOptionsSnapshot();
        }
    }

    /**
     * How many cases of the run carry one of the given verdicts (all verdicts when null).
     *
     * @param AgentEvalSuiteRun $agentEvalSuiteRun
     * @param string[]|null $verdicts
     * @return int
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function countCasesOfSuiteRun(AgentEvalSuiteRun $agentEvalSuiteRun, ?array $verdicts): int
    {
        $agentEvalSuiteCasesClass = $this->getAgentEvalSuiteCasesClass();
        $agentEvalSuiteCasesClass::setDefaultQueryOptionsSnapshot();
        try {
            $caseFilters = $this->buildFiltersForSuiteRun($agentEvalSuiteRun, $verdicts);
            $agentEvalSuiteCasesClass::getDefaultQueryOptions()->setFilters($caseFilters);
            return $this->countAll();
        } finally {
            $agentEvalSuiteCasesClass::restoreDefaultQueryOptionsSnapshot();
        }
    }

    /**
     * The filter of a run's cases: the run, and when verdicts are given one of them.
     *
     * @param AgentEvalSuiteRun $agentEvalSuiteRun
     * @param string[]|null $verdicts
     * @return FiltersOptions
     */
    protected function buildFiltersForSuiteRun(AgentEvalSuiteRun $agentEvalSuiteRun, ?array $verdicts): FiltersOptions
    {
        $runFilter = FiltersOptions::createExpression('agentEvalSuiteRunId', FiltersOptions::OPERATOR_EQUAL, (string)$agentEvalSuiteRun->id);
        if ($verdicts === null || $verdicts === []) {
            return $runFilter;
        }
        $verdictFilter = FiltersOptions::createExpression('verdict', FiltersOptions::OPERATOR_IN, array_values($verdicts));
        return FiltersOptions::buildConnected(FiltersOptions::JOIN_OPERATOR_AND, $runFilter, $verdictFilter);
    }

    /**
     * The case set class the container resolves: an application's same-path subclass when it has one.
     *
     * @return class-string<AgentEvalSuiteCases>
     */
    protected function getAgentEvalSuiteCasesClass(): string
    {
        /** @var class-string<AgentEvalSuiteCases> $agentEvalSuiteCasesClass */
        $agentEvalSuiteCasesClass = DDDService::instance()->getContainerServiceClassNameForClass(AgentEvalSuiteCases::class) ?? AgentEvalSuiteCases::class;
        return $agentEvalSuiteCasesClass;
    }
}
