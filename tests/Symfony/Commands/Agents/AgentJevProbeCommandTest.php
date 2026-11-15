<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentProbeDecisionAnswer;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentProbeDecisionAnswers;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\AgentJevProbeCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The decision probe resolves the fixture's agent space and its owner, runs every case as the owner under an
 * evaluation envelope billed to the owner, prints each answer beside its label with the hit rate per score key, and
 * writes the rows with their six keys to the result file. A space it cannot resolve ends the run before any call.
 */
class AgentJevProbeCommandTest extends UnitTestCase
{
    protected ?Account $accountBeforeTest = null;

    protected AgentSpacesServiceConsoleScopeDouble $agentSpacesServiceDouble;

    protected RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble;

    /** @var string[] The files a test wrote; removed in tearDown() */
    protected array $writtenFilePaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBeforeTest = AuthService::instance()->getAccount();
        self::setSingletonInstance(AuthService::class, new CountingAuthServiceDouble());
        $this->agentSpacesServiceDouble = new AgentSpacesServiceConsoleScopeDouble();
        $this->usageEnvelopesServiceDouble = new RecordingUsageEnvelopesServiceDouble();
    }

    protected function tearDown(): void
    {
        foreach ($this->writtenFilePaths as $writtenFilePath) {
            if (is_file($writtenFilePath)) {
                unlink($writtenFilePath);
            }
        }
        AuthService::instance()->setAccount($this->accountBeforeTest);
        self::resetSingletonInstance(AuthService::class);
        parent::tearDown();
    }

    public function testTheCommandKeepsItsNameArgumentAndOptions(): void
    {
        $asCommandAttributes = (new ReflectionClass(AgentJevProbeCommand::class))->getAttributes(AsCommand::class);
        self::assertSame('app:ai-agent:jev-probe', $asCommandAttributes[0]->newInstance()->name);

        $agentJevProbeCommand = new AgentJevProbeCommand();
        self::assertInstanceOf(AgentConsoleCommand::class, $agentJevProbeCommand);
        $commandDefinition = $agentJevProbeCommand->getDefinition();
        self::assertTrue($commandDefinition->getArgument('fixture')->isRequired());
        self::assertSame(['floor', 'resultFile', AgentConsoleCommand::OPTION_IGNORE_BUDGET], array_keys($commandDefinition->getOptions()));
        self::assertSame('0.5', $commandDefinition->getOption('floor')->getDefault());
    }

    public function testEveryCaseIsAnsweredScoredAndWrittenAsTheOwnerUnderAnEvaluationEnvelope(): void
    {
        $probeCommand = $this->buildProbeCommandWithScriptedAnswers();
        $resultFilePath = $this->reserveFilePath('jev-probe-result');

        $commandTester = new CommandTester($probeCommand);
        $exitCode = $commandTester->execute(['fixture' => $this->writeFixture(AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID), '--resultFile' => $resultFilePath]);
        $display = $commandTester->getDisplay();

        self::assertSame(0, $exitCode);
        $ownerAccountId = AgentSpacesServiceConsoleScopeDouble::OWNER_ACCOUNT_ID;
        self::assertSame(
            [
                "a:envelope=open:account={$ownerAccountId}:budget={$ownerAccountId}:billing=none",
                "b:envelope=open:account={$ownerAccountId}:budget={$ownerAccountId}:billing=none",
                "broken:envelope=open:account={$ownerAccountId}:budget={$ownerAccountId}:billing=none",
            ],
            $probeCommand->observedCalls
        );
        self::assertCount(3, $this->usageEnvelopesServiceDouble->openedEnvelopeContexts);
        self::assertCount(3, $this->usageEnvelopesServiceDouble->closedEnvelopeContexts, 'the envelope of the failed call is closed too');
        foreach ($this->usageEnvelopesServiceDouble->openedEnvelopeContexts as $openedEnvelopeContext) {
            self::assertSame(AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION, $openedEnvelopeContext->scope);
            self::assertSame($ownerAccountId, $openedEnvelopeContext->accountId);
        }
        self::assertMatchesRegularExpression('/^\s*a\s+isOpen\s+0\.80\s+true\s+ok\s*$/m', $display);
        self::assertMatchesRegularExpression('/^\s*a\s+kind\s+cafe \(0\.90\)\s+"cafe"\s+ok\s*$/m', $display);
        self::assertMatchesRegularExpression('/^\s*b\s+isOpen\s+0\.70\s+false\s+MISS\s*$/m', $display);
        self::assertMatchesRegularExpression('/^\s*b\s+kind\s+bar \(0\.60\)\s+null\s*$/m', $display);
        self::assertMatchesRegularExpression('/^\s*broken\s+isOpen\s+ERR ' . AgentJevProbeProbeCommand::CALL_FAILURE_MESSAGE . '\s+true\s*$/m', $display);
        self::assertStringContainsString("isOpen: 1/1 at floor 0.50\nkind: 1/1 at floor 0.50\nlate: 0/1 at floor 0.50", $display);

        $writtenRows = json_decode((string)file_get_contents($resultFilePath), true);
        self::assertCount(5, $writtenRows, 'two questions for a and b, the own question of broken');
        self::assertSame(['case', 'question', 'answer', 'expected', 'hit', 'failure'], array_keys($writtenRows[0]));
        self::assertSame(['case' => 'b', 'question' => 'isOpen', 'expected' => false, 'hit' => false, 'failure' => null], array_diff_key($writtenRows[2], ['answer' => true]));
        self::assertSame(0.7, $writtenRows[2]['answer']['yesProbability']);
        self::assertSame(['case' => 'broken', 'question' => 'isOpen', 'answer' => null, 'expected' => true, 'hit' => null, 'failure' => AgentJevProbeProbeCommand::CALL_FAILURE_MESSAGE], $writtenRows[4]);
        self::assertNull(AuthService::instance()->getAccount(), 'the run acted as the owner; the account of before the run is authenticated again');
    }

    public function testAFixtureWhoseSpaceCannotBeResolvedEndsTheRunBeforeAnyCall(): void
    {
        $probeCommand = $this->buildProbeCommandWithScriptedAnswers();

        $commandTester = new CommandTester($probeCommand);
        $exitCode = $commandTester->execute(['fixture' => $this->writeFixture(AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID + 1)]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString("The fixture's agentSpaceId names no stored agent space, or the space's owning account could not be resolved; the probe creates no space.", preg_replace('/\s+/', ' ', $commandTester->getDisplay()));
        self::assertSame([], $probeCommand->observedCalls);
        self::assertSame([], $this->usageEnvelopesServiceDouble->openedEnvelopeContexts);
    }

    public function testAMissingFixtureEndsTheRun(): void
    {
        $commandTester = new CommandTester($this->buildProbeCommandWithScriptedAnswers());
        $exitCode = $commandTester->execute(['fixture' => sys_get_temp_dir() . '/agent-jev-probe-fixture-that-does-not-exist.json']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Fixture not found', $commandTester->getDisplay());
    }

    /**
     * Case `a` answers both questions right; case `b` answers yes where no is expected and counts its answers under
     * `late`; case `broken` has no scripted answers, so its call fails.
     */
    protected function buildProbeCommandWithScriptedAnswers(): AgentJevProbeProbeCommand
    {
        $probeCommand = new AgentJevProbeProbeCommand($this->agentSpacesServiceDouble, $this->usageEnvelopesServiceDouble);
        $probeCommand->answersByCaseId['a'] = $this->buildAnswers(0.8, 'cafe', 0.9);
        $probeCommand->answersByCaseId['b'] = $this->buildAnswers(0.7, 'bar', 0.6);
        return $probeCommand;
    }

    protected function buildAnswers(float $isOpenProbability, string $kindChoice, float $kindConfidence): AgentProbeDecisionAnswers
    {
        $agentProbeDecisionAnswers = new AgentProbeDecisionAnswers();
        $isOpenAnswer = AgentProbeDecisionAnswer::forYesProbability('isOpen', $isOpenProbability);
        $kindAnswer = AgentProbeDecisionAnswer::forChoice('kind', $kindChoice, $kindConfidence);
        $agentProbeDecisionAnswers->add($isOpenAnswer, $kindAnswer);
        return $agentProbeDecisionAnswers;
    }

    protected function writeFixture(int $agentSpaceId): string
    {
        $fixturePath = $this->reserveFilePath('jev-probe-fixture');
        file_put_contents($fixturePath, (string)json_encode([
            'agentSpaceId' => $agentSpaceId,
            'questions' => ['isOpen' => ['type' => 'yesNo'], 'kind' => ['type' => 'choice']],
            'cases' => [
                ['id' => 'a', 'state' => ['caseId' => 'a'], 'expect' => ['isOpen' => true, 'kind' => 'cafe']],
                ['id' => 'b', 'state' => ['caseId' => 'b'], 'expect' => ['isOpen' => false], 'scoreAs' => 'late'],
                ['id' => 'broken', 'state' => ['caseId' => 'broken'], 'questions' => ['isOpen' => ['type' => 'yesNo']], 'expect' => ['isOpen' => true]],
            ],
        ]));
        return $fixturePath;
    }

    protected function reserveFilePath(string $prefix): string
    {
        $filePath = (string)tempnam(sys_get_temp_dir(), $prefix);
        $this->writtenFilePaths[] = $filePath;
        return $filePath;
    }
}
