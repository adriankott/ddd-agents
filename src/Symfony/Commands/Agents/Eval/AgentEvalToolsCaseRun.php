<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRowHeader;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRunMeasurement;
use DDD\Domain\AI\Entities\Evals\AgentEvalFixture;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgedRunOptions;
use DDD\Domain\Base\Entities\ValueObject;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One case of the tools evaluation as this process drives it: the case (with the purpose its fixture gives it), its
 * fixture, the scope the planning assigned it, the options of its run and the moment its run started.
 *
 * The heads of its rows are built here, each a new object in the stored order: `skill`, `case`, `tool`
 * ({@see buildRowHeader()}), and for a case that does not apply also `flavor` and the scope
 * ({@see buildNotApplicableRowHeader()}).
 *
 * A plain object of one command run; no container service, never stored.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentEvalToolsCaseRun extends ValueObject
{
    /** @var AgentEvalCase The case, its purpose resolved against its fixture */
    public AgentEvalCase $agentEvalCase;

    /** @var AgentEvalFixture The fixture the case belongs to */
    public AgentEvalFixture $agentEvalFixture;

    /** @var int|null The scope of the case as the planning assigned it (an application's own scope id) */
    public ?int $scopeId = null;

    /** @var AgentEvalJudgedRunOptions The options of the case's run (one object per case) */
    public AgentEvalJudgedRunOptions $runOptions;

    /** @var float The moment the case's run started (`microtime(true)`) */
    public float $startedAtMicrotime = 0.0;

    /**
     * The run of `$agentEvalCase` of `$agentEvalFixture` with the scope `$scopeId`; the purpose of the case is the
     * case's own, else the fixture's ({@see AgentEvalFixture::resolvePurposeNameOfCase()}).
     */
    public static function forCaseOfFixture(AgentEvalCase $agentEvalCase, AgentEvalFixture $agentEvalFixture, ?int $scopeId, AgentEvalJudgedRunOptions $runOptions): static
    {
        $caseFields = $agentEvalCase->toArray();
        $purposeName = $agentEvalFixture->resolvePurposeNameOfCase($agentEvalCase);
        if ($purposeName !== null) {
            $caseFields['purposeName'] = $purposeName;
        }
        $agentEvalToolsCaseRun = static::newInstance();
        $agentEvalToolsCaseRun->agentEvalCase = AgentEvalCase::fromArray($caseFields);
        $agentEvalToolsCaseRun->agentEvalFixture = $agentEvalFixture;
        $agentEvalToolsCaseRun->scopeId = $scopeId;
        $agentEvalToolsCaseRun->runOptions = $runOptions;
        return $agentEvalToolsCaseRun;
    }

    /** The case id. */
    public function getCaseId(): string
    {
        return $this->agentEvalCase->getId();
    }

    /** The skill of the case's fixture. */
    public function getSkillName(): string
    {
        return $this->agentEvalFixture->getSkillName();
    }

    /** The label of the expected tool ({@see AgentEvalCase::getExpectedToolLabel()}). */
    public function getToolLabel(): string
    {
        return $this->agentEvalCase->getExpectedToolLabel();
    }

    /** Notes the moment the case's run starts. */
    public function markRunStarted(): void
    {
        $this->startedAtMicrotime = microtime(true);
    }

    /** The wall milliseconds since the case's run started. */
    public function measureDurationMs(): int
    {
        return AgentEvalCaseRunMeasurement::measureDurationMsSince($this->startedAtMicrotime);
    }

    /** A new head of the case's rows: `skill`, `case`, `tool`. */
    public function buildRowHeader(): AgentEvalCaseRowHeader
    {
        return AgentEvalCaseRowHeader::forCase($this->getSkillName(), $this->getCaseId(), $this->getToolLabel());
    }

    /** A new head of the row of a case that does not apply: `skill`, `case`, `tool`, `flavor`, the scope. */
    public function buildNotApplicableRowHeader(string $flavor): AgentEvalCaseRowHeader
    {
        return $this->buildRowHeader()->addFlavor($flavor)->addScope($this->scopeId);
    }
}
