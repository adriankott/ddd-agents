<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentDecisions;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentProbeDecision;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\DDDService;
use DDD\Symfony\Commands\Agents\JevProbe\AgentJevProbeAnswerRow;
use DDD\Symfony\Commands\Agents\JevProbe\AgentJevProbeScope;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Probes a decision-model question on labelled cases before a decision site is built. The fixture:
 * `{"agentSpaceId": N, "questions": {name: {type, instructions, criteria}}, "cases": [{"id", "state": {…},
 * "expect": {name: true|false|"<choice>"}}]}` — the same questions for every case (a case may carry its own
 * `questions`), the state per case. Prints each answer beside its label and the hit rate per question (a case's
 * `scoreAs` counts its answers under another key); a yes-probability answer counts as true from `--floor` on.
 *
 * The run acts as the account that owns the fixture's agent space, the calls are billed to the space's billing entity,
 * and each case's call is booked under an evaluation envelope. An application whose fixtures name the space by a key
 * of its own overrides {@see getFixtureAgentSpaceKey()} and {@see findAgentSpaceOfFixture()}.
 */
#[AsCommand(
    name: 'app:agent:jev-probe',
    aliases: ['app:ai-agent:jev-probe'],
    description: 'Send labelled cases of a fixture to the decision model (Jev) and score the answers per question.'
)]
class AgentJevProbeCommand extends AgentConsoleCommand
{
    /** @var string The fixture key that names the agent space by its id */
    public const string FIXTURE_AGENT_SPACE_ID_KEY = 'agentSpaceId';

    /** @var string The batch key of the probe decision */
    public const string PROBE_DECISION_KEY_IN_BATCH = 'probe';

    /** @var float The decision timeout of a probe call never goes below this (seconds) */
    public const float MINIMUM_PROBE_TIMEOUT_SECONDS = 4.0;

    protected function configure(): void
    {
        $this
            ->addArgument('fixture', InputArgument::REQUIRED, 'Path of the JSON fixture (absolute, or relative to the app dir)')
            ->addOption('floor', null, InputOption::VALUE_REQUIRED, 'A noul answer counts as true from this probability on', '0.5')
            ->addOption('resultFile', null, InputOption::VALUE_REQUIRED, 'Write every answer as JSON here');
        $this->configureIgnoreBudgetOption();
    }

    /**
     * Reads the fixture, resolves whom the probe runs for, sends every case and prints the answers and hit rates.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int `FAILURE` when the fixture is missing or its agent space or owner cannot be resolved
     * @throws JsonException when the fixture is no valid JSON
     * @throws Throwable
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(1800);
        $symfonyStyle = new SymfonyStyle($input, $output);
        $fixturePath = (string)$input->getArgument('fixture');
        if (!is_file($fixturePath)) {
            $symfonyStyle->error("Fixture not found: $fixturePath");
            return Command::FAILURE;
        }
        $fixture = (array)json_decode((string)file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        $floor = (float)$input->getOption('floor');

        $probeScope = $this->resolveProbeScopeOfFixture($fixture);
        if ($probeScope === null) {
            $symfonyStyle->error("The fixture's {$this->getFixtureAgentSpaceKey()} names no stored agent space, or the space's owning account could not be resolved; the probe creates no space.");
            return Command::FAILURE;
        }
        $this->actAsAccount($probeScope->ownerAccount);
        $timeoutSeconds = max(AgentDecisionModelSettings::current()->timeoutSeconds, self::MINIMUM_PROBE_TIMEOUT_SECONDS);

        $answerRows = [];
        $fixtureQuestions = (array)($fixture['questions'] ?? []);
        foreach ((array)($fixture['cases'] ?? []) as $case) {
            array_push($answerRows, ...$this->probeCase((array)$case, $fixtureQuestions, $probeScope, $timeoutSeconds, $floor));
        }
        $this->printAnswerRows($answerRows, $floor, $symfonyStyle);
        $resultFile = (string)($input->getOption('resultFile') ?? '');
        if ($resultFile !== '') {
            file_put_contents($resultFile, (string)json_encode($answerRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        return Command::SUCCESS;
    }

    /**
     * The fixture key that names the agent space (it appears in the error when the space cannot be resolved). The
     * default: `agentSpaceId`.
     *
     * @return string
     */
    protected function getFixtureAgentSpaceKey(): string
    {
        return self::FIXTURE_AGENT_SPACE_ID_KEY;
    }

    /**
     * The agent space the fixture names; asked with the entity rights lifted. The default reads the id under
     * {@see getFixtureAgentSpaceKey()} and finds the stored space.
     *
     * @param array<string, mixed> $fixture
     * @return AgentSpace|null null = the fixture names no space that exists
     * @throws Throwable
     */
    protected function findAgentSpaceOfFixture(array $fixture): ?AgentSpace
    {
        $agentSpaceId = (int)($fixture[$this->getFixtureAgentSpaceKey()] ?? 0);
        if ($agentSpaceId <= 0) {
            return null;
        }
        $agentSpacesService = $this->getAgentSpacesService();
        $agentSpace = $agentSpacesService->find($agentSpaceId);
        return $agentSpace instanceof AgentSpace ? $agentSpace : null;
    }

    /**
     * The agent space of the fixture, its owner account and its billing entity, read with the entity rights lifted
     * (whoever runs the probe, the space's owner must be found).
     *
     * @param array<string, mixed> $fixture
     * @return AgentJevProbeScope|null null = no space, or a space without an owner
     * @throws Throwable
     */
    protected function resolveProbeScopeOfFixture(array $fixture): ?AgentJevProbeScope
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentSpace = $this->findAgentSpaceOfFixture($fixture);
            $ownerAccount = $agentSpace !== null ? $this->findOwnerAccountOfAgentSpace($agentSpace) : null;
            if ($agentSpace === null || !$ownerAccount instanceof Account) {
                return null;
            }
            return new AgentJevProbeScope($agentSpace, $ownerAccount, $agentSpace->findBillingEntity());
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * Sends one case to the decision model and scores its answers: one row per question of the case.
     *
     * @param array<string, mixed> $case
     * @param array<int|string, mixed> $fixtureQuestions the questions of every case that carries none of its own
     * @param AgentJevProbeScope $probeScope
     * @param float $timeoutSeconds
     * @param float $floor
     * @return AgentJevProbeAnswerRow[]
     */
    protected function probeCase(array $case, array $fixtureQuestions, AgentJevProbeScope $probeScope, float $timeoutSeconds, float $floor): array
    {
        $caseQuestions = (array)($case['questions'] ?? $fixtureQuestions);
        $argusDecision = ArgusAgentProbeDecision::newInstance();
        $argusDecision->probeQuestions = $caseQuestions;
        $argusDecision->probeState = (array)($case['state'] ?? []);
        $argusDecision->billingEntity = $probeScope->billingEntity;
        $argusDecision->budgetAccount = $probeScope->ownerAccount;
        $failure = null;
        try {
            $usageEnvelopeContext = $this->openUsageEnvelope((int)$probeScope->ownerAccount->id, AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION);
            try {
                $this->loadProbeDecision($argusDecision, $timeoutSeconds);
            } finally {
                $this->closeUsageEnvelope($usageEnvelopeContext);
            }
            if (!$argusDecision->decided) {
                $failure = $argusDecision->transportFailureReason ?? 'unparsable';
            }
        } catch (Throwable $callError) {
            $failure = mb_substr($callError->getMessage(), 0, 120);
        }
        $answerRows = [];
        foreach (array_keys($caseQuestions) as $questionName) {
            $answer = $argusDecision->probeAnswers?->getAnswerOfQuestion((string)$questionName);
            $expected = $case['expect'][$questionName] ?? null;
            $given = $answer === null ? null : ($answer->isYesProbability() ? $answer->yesProbability >= $floor : $answer->choice);
            $answerRows[] = new AgentJevProbeAnswerRow(
                (string)($case['id'] ?? '?'),
                $questionName,
                $answer,
                $expected,
                $expected === null || $given === null ? null : $given === $expected,
                $failure,
                (string)($case['scoreAs'] ?? $questionName),
            );
        }
        return $answerRows;
    }

    /**
     * The model call of one case: the probe decision as the only child of a decision batch.
     *
     * @param ArgusAgentProbeDecision $argusDecision
     * @param float $timeoutSeconds
     * @return void
     * @throws Throwable
     */
    protected function loadProbeDecision(ArgusAgentProbeDecision $argusDecision, float $timeoutSeconds): void
    {
        ArgusAgentDecisions::forChild($argusDecision, self::PROBE_DECISION_KEY_IN_BATCH)->loadDecisions($timeoutSeconds);
    }

    /**
     * Prints the table of every answer and the hit rate per score key, in the order the keys were first scored.
     *
     * @param AgentJevProbeAnswerRow[] $answerRows
     * @param float $floor
     * @param SymfonyStyle $symfonyStyle
     * @return void
     */
    protected function printAnswerRows(array $answerRows, float $floor, SymfonyStyle $symfonyStyle): void
    {
        $tableRows = [];
        $scoredCountsByScoreKey = [];
        $hitCountsByScoreKey = [];
        foreach ($answerRows as $answerRow) {
            $tableRows[] = [
                $answerRow->caseId,
                $answerRow->questionName,
                $answerRow->getPrintedAnswerText(),
                json_encode($answerRow->expected),
                $answerRow->hit === null ? '' : ($answerRow->hit ? 'ok' : 'MISS'),
            ];
            if ($answerRow->hit !== null) {
                $scoredCountsByScoreKey[$answerRow->scoreKey] = ($scoredCountsByScoreKey[$answerRow->scoreKey] ?? 0) + 1;
                $hitCountsByScoreKey[$answerRow->scoreKey] = ($hitCountsByScoreKey[$answerRow->scoreKey] ?? 0) + ($answerRow->hit ? 1 : 0);
            }
        }
        $symfonyStyle->table(['case', 'question', 'answer', 'expected', ''], $tableRows);
        foreach ($scoredCountsByScoreKey as $scoreKey => $scoredCount) {
            $symfonyStyle->writeln(sprintf('%s: %d/%d at floor %.2f', $scoreKey, $hitCountsByScoreKey[$scoreKey] ?? 0, $scoredCount, $floor));
        }
    }
}
