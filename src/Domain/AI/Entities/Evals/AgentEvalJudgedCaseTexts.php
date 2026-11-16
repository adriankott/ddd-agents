<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The texts a judged run of a tools case takes when the case names none of its own: what the simulated owner wants
 * (`goal`), what it knows (`userFacts`) and what the judge checks (`successRubric`). A case's own text always wins
 * ({@see AgentEvalSimCase::fromEvaluatedCase()}).
 *
 * The module's texts are neutral English and name the space with the application's word
 * ({@see AgentApplicationWording::$agentSpaceName}). An application that has always judged with texts of its own
 * writes them in {@see fillDefaultsOfCase()} on its same-path class; {@see forCase()} builds the object with
 * `newInstance()`, which gives that class.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalJudgedCaseTexts extends ValueObject
{
    /** @var string What the simulated owner wants */
    public string $goal = '';

    /** @var string What the simulated owner knows and answers from */
    public string $ownerFacts = '';

    /** @var string What the judge checks */
    public string $successRubric = '';

    /** The default texts of `$agentEvalCase`. */
    public static function forCase(AgentEvalCase $agentEvalCase): static
    {
        $agentEvalJudgedCaseTexts = static::newInstance();
        $agentEvalJudgedCaseTexts->fillDefaultsOfCase($agentEvalCase);
        return $agentEvalJudgedCaseTexts;
    }

    /**
     * Fills the three texts from the case's prompt and its expected tool ({@see AgentEvalCase::getExpectedToolLabel()}).
     */
    public function fillDefaultsOfCase(AgentEvalCase $agentEvalCase): void
    {
        $agentSpaceName = AgentApplicationWording::current()->agentSpaceName;
        $this->goal = 'The owner wants this answered or done: ' . $agentEvalCase->getPrompt();
        $this->ownerFacts = "You are the owner of exactly this one {$agentSpaceName}. When the agent asks for a period, a "
            . 'competitor or a keyword you do not have at hand, name something plausible or leave the sensible default to '
            . 'it — do not block.';
        $this->successRubric = 'Success = the agent gives an answer that is GROUNDED — backed by real tool results OR by the '
            . "master data of the {$agentSpaceName} in its system prompt (answering from that context is grounded and needs NO tool) — "
            . 'and that actually addresses what the owner asked, without invented figures or facts. '
            . 'Which exact tool it takes is secondary as long as the statement is backed by tool data or that context '
            . '(intended tool context: ' . $agentEvalCase->getExpectedToolLabel() . '). '
            . 'A short, sensible question back before delivering is fine; merely repeating the question is a FAIL.';
    }
}
