<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgedCaseTexts;

/**
 * The default judge texts of an application that has always judged with texts of its own.
 */
class AgentEvalJudgedCaseTextsOfAnApplication extends AgentEvalJudgedCaseTexts
{
    public function fillDefaultsOfCase(AgentEvalCase $agentEvalCase): void
    {
        $this->goal = 'Wants: ' . $agentEvalCase->getPrompt();
        $this->ownerFacts = 'facts of the application';
        $this->successRubric = 'rubric of the application';
    }
}
