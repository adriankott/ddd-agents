<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteCases;

use DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\AgentEvalSuiteCases\DBAgentEvalSuiteCases;
use DDD\Domain\AI\Services\Eval\AgentEvalSuiteCasesService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * Cases of an evaluation run ({@see AgentEvalSuiteCase}), in the order they finished.
 *
 * @property AgentEvalSuiteCase[] $elements
 * @method AgentEvalSuiteCase[] getElements()
 * @method AgentEvalSuiteCase|null first()
 * @method AgentEvalSuiteCase|null getByUniqueKey(string $uniqueKey)
 * @method static AgentEvalSuiteCasesService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentEvalSuiteCases::class)]
class AgentEvalSuiteCases extends EntitySet implements LlmMarkdownRenderable
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AgentEvalSuiteCasesService::class;

    /**
     * One line per case, its findings below it fenced as data: the findings quote the judge (a model) and error texts.
     */
    public function toLlmMarkdown(): string
    {
        $lines = [];
        foreach ($this->getElements() as $agentEvalSuiteCase) {
            $lines[] = '- ' . $agentEvalSuiteCase->getSummaryLine();
            if ($agentEvalSuiteCase->findings !== null) {
                $lines[] = '  <untrusted source="model">' . str_replace("\n", "\n  ", $agentEvalSuiteCase->findings) . '</untrusted>';
            }
        }
        return $lines === [] ? 'No case matches.' : implode("\n", $lines);
    }
}
