<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\SuiteRuns;

use DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\DBAgentEvalSuiteRuns;
use DDD\Domain\AI\Services\Eval\AgentEvalSuiteRunsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * Finished evaluation runs ({@see AgentEvalSuiteRun}).
 *
 * @property AgentEvalSuiteRun[] $elements
 * @method AgentEvalSuiteRun[] getElements()
 * @method AgentEvalSuiteRun|null first()
 * @method AgentEvalSuiteRun|null getByUniqueKey(string $uniqueKey)
 * @method static AgentEvalSuiteRunsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentEvalSuiteRuns::class)]
class AgentEvalSuiteRuns extends EntitySet implements LlmMarkdownRenderable
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AgentEvalSuiteRunsService::class;

    /** One summary line per run. */
    public function toLlmMarkdown(): string
    {
        $lines = [];
        foreach ($this->getElements() as $agentEvalSuiteRun) {
            $lines[] = '- ' . $agentEvalSuiteRun->getSummaryLine();
        }
        return $lines === [] ? 'No eval run matches.' : implode("\n", $lines);
    }
}
