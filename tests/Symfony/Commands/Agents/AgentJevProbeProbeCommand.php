<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentProbeDecisionAnswers;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentProbeDecision;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Symfony\Commands\Agents\AgentJevProbeCommand;
use RuntimeException;

/**
 * The decision probe with its services handed in and the model call replaced: the answers of a case come from the
 * test, a case without scripted answers fails like a gateway timeout. Records what each call saw: the open envelope,
 * the authenticated account, the budget account and the billing entity.
 */
class AgentJevProbeProbeCommand extends AgentJevProbeCommand
{
    /** @var string The name of the probe; its own, so it never meets the real command */
    public const string PROBE_COMMAND_NAME = 'app:test:jev-probe-probe';

    public const string CALL_FAILURE_MESSAGE = 'gateway timed out';

    /** @var array<string, AgentProbeDecisionAnswers> The answers of a case, by the state's `caseId` */
    public array $answersByCaseId = [];

    /** @var string[] What each call saw, as `<case id>:envelope=<open>:account=<id>:budget=<id>:billing=<class or none>` */
    public array $observedCalls = [];

    public function __construct(
        protected AgentSpacesServiceConsoleScopeDouble $agentSpacesServiceDouble,
        protected RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble
    ) {
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    protected function loadProbeDecision(ArgusAgentProbeDecision $argusDecision, float $timeoutSeconds): void
    {
        $caseId = (string)($argusDecision->probeState['caseId'] ?? '');
        $this->observedCalls[] = sprintf(
            '%s:envelope=%s:account=%s:budget=%s:billing=%s',
            $caseId,
            $this->usageEnvelopesServiceDouble->hasActiveEnvelope() ? 'open' : 'none',
            AuthService::instance()->getAccount()?->id ?? '-',
            $argusDecision->budgetAccount?->id ?? '-',
            $argusDecision->billingEntity !== null ? $argusDecision->billingEntity::class : 'none'
        );
        if (!isset($this->answersByCaseId[$caseId])) {
            throw new RuntimeException(self::CALL_FAILURE_MESSAGE);
        }
        $argusDecision->probeAnswers = $this->answersByCaseId[$caseId];
        $argusDecision->decided = true;
    }

    protected function getAgentSpacesService(): AgentSpacesService
    {
        return $this->agentSpacesServiceDouble;
    }

    protected function getUsageEnvelopesService(): AIUsageEnvelopesService
    {
        return $this->usageEnvelopesServiceDouble;
    }
}
