<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\DecisionFixtures;

use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Symfony\Commands\Agents\AgentDecisionFixturesCommand;
use DDD\Tests\Agents\Symfony\Commands\Agents\AgentSpacesServiceConsoleScopeDouble;
use DDD\Tests\Agents\Symfony\Commands\Agents\RecordingUsageEnvelopesServiceDouble;
use RuntimeException;

/**
 * The decision-fixtures command with its services handed in and the decision model replaced by a stub: the answer of a
 * call comes from the test, keyed by the owner text of the case; a case without a scripted answer fails like a gateway
 * timeout. Records what each call saw and the decision it was sent.
 */
class AgentDecisionFixturesProbeCommand extends AgentDecisionFixturesCommand
{
    /** @var string The name of the probe; its own, so it never meets the real command */
    public const string PROBE_COMMAND_NAME = 'app:test:decision-fixtures-probe';

    public const string CALL_FAILURE_MESSAGE = 'gateway timed out';

    /** @var array<string, ArgusAgentTurnStartDecision> The answer of a call, by the owner text of its case; the answer fields are copied onto the call's decision */
    public array $scriptedAnswersByOwnerText = [];

    /** @var string[] What each call saw, as `<owner text>:envelope=<open|none>:account=<id>:budget=<id>:billing=<class or none>` */
    public array $observedCalls = [];

    /** @var ArgusAgentTurnStartDecision[] The decisions sent, in order */
    public array $sentDecisions = [];

    public function __construct(
        protected AgentSpacesServiceConsoleScopeDouble $agentSpacesServiceDouble,
        protected RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble,
        protected AISkillsServiceWithFixtureSkillsDouble $skillsServiceDouble
    ) {
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    protected function loadDecision(ArgusAgentTurnStartDecision $argusDecision, float $timeoutSeconds): void
    {
        $ownerText = $argusDecision->ownerWordsThisTurn !== '' ? $argusDecision->ownerWordsThisTurn : $argusDecision->preloadOwnerMessage;
        $this->sentDecisions[] = $argusDecision;
        $this->observedCalls[] = sprintf(
            '%s:envelope=%s:account=%s:budget=%s:billing=%s',
            $ownerText,
            $this->usageEnvelopesServiceDouble->hasActiveEnvelope() ? 'open' : 'none',
            AuthService::instance()->getAccount()?->id ?? '-',
            $argusDecision->budgetAccount?->id ?? '-',
            $argusDecision->billingEntity !== null ? $argusDecision->billingEntity::class : 'none'
        );
        $scriptedAnswer = $this->scriptedAnswersByOwnerText[$ownerText] ?? null;
        if ($scriptedAnswer === null) {
            throw new RuntimeException(self::CALL_FAILURE_MESSAGE);
        }
        $argusDecision->writtenLanguageCode = $scriptedAnswer->writtenLanguageCode;
        $argusDecision->writtenConfidence = $scriptedAnswer->writtenConfidence;
        $argusDecision->requestedLanguageCode = $scriptedAnswer->requestedLanguageCode;
        $argusDecision->requestedConfidence = $scriptedAnswer->requestedConfidence;
        $argusDecision->preloadPickedToken = $scriptedAnswer->preloadPickedToken;
        $argusDecision->preloadPickConfidence = $scriptedAnswer->preloadPickConfidence;
        $argusDecision->preloadProbabilities = $scriptedAnswer->preloadProbabilities;
    }

    protected function getAgentSpacesService(): AgentSpacesService
    {
        return $this->agentSpacesServiceDouble;
    }

    protected function getUsageEnvelopesService(): AIUsageEnvelopesService
    {
        return $this->usageEnvelopesServiceDouble;
    }

    protected function getSkillsService(): AISkillsService
    {
        return $this->skillsServiceDouble;
    }
}
