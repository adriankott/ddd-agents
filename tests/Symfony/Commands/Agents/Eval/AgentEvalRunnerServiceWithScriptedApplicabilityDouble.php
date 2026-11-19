<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalNotApplicableReason;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOptions;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;

/**
 * The runner whose applicability check answers what the test scripted per case id (no scope is read); every case not
 * named applies. The fallback agent space each check received is kept.
 */
class AgentEvalRunnerServiceWithScriptedApplicabilityDouble extends AgentEvalRunnerService
{
    /** @var string[] Case id → the detail of its not-applicable reason */
    public array $notApplicableDetailByCaseId = [];

    /** @var string The reason of every scripted not-applicable case */
    public const string SCRIPTED_REASON = 'ineligibleScope';

    /** @var array<int, int|null> The fallback agent space of every check, in order */
    public array $fallbackAgentSpaceIdsOfChecks = [];

    /** @var array<int, int|null> The account each check of a run that names one received, in order */
    public array $fallbackAccountIdsOfChecks = [];

    public function resolveScopeWithFallbackAccountAndFindNotApplicableReasonForCase(AgentEvalCase $agentEvalCase, ?int $fallbackAgentSpaceId, ?int $fallbackAccountId, AgentEvalRunOptions $agentEvalRunOptions): ?AgentEvalNotApplicableReason
    {
        $this->fallbackAccountIdsOfChecks[] = $fallbackAccountId;
        if ($fallbackAccountId === null) {
            return $this->resolveScopeAndFindNotApplicableReasonForCase($agentEvalCase, $fallbackAgentSpaceId, $agentEvalRunOptions);
        }
        $notApplicableDetail = $this->notApplicableDetailByCaseId[$agentEvalCase->getId()] ?? null;
        return $notApplicableDetail !== null ? AgentEvalNotApplicableReason::withReasonAndDetail(self::SCRIPTED_REASON, $notApplicableDetail) : null;
    }

    public function resolveScopeAndFindNotApplicableReasonForCase(AgentEvalCase $agentEvalCase, ?int $fallbackAgentSpaceId, AgentEvalRunOptions $agentEvalRunOptions): ?AgentEvalNotApplicableReason
    {
        $this->fallbackAgentSpaceIdsOfChecks[] = $fallbackAgentSpaceId;
        $notApplicableDetail = $this->notApplicableDetailByCaseId[$agentEvalCase->getId()] ?? null;
        return $notApplicableDetail !== null ? AgentEvalNotApplicableReason::withReasonAndDetail(self::SCRIPTED_REASON, $notApplicableDetail) : null;
    }
}
