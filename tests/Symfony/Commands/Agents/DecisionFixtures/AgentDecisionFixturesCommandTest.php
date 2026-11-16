<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\DecisionFixtures;

use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\AgentDecisionFixturesCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Symfony\Commands\Agents\AgentSpacesServiceConsoleScopeDouble;
use DDD\Tests\Agents\Symfony\Commands\Agents\CountingAuthServiceDouble;
use DDD\Tests\Agents\Symfony\Commands\Agents\RecordingUsageEnvelopesServiceDouble;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The decision-fixtures command with a stub decision: it resolves the fixture's agent space and its owner, sends every
 * case as the owner under its own evaluation envelope billed to the owner, and scores the answers — the sub-skill pass
 * on the pick, the reply-language site on the written and requested answers and the resolved reply. A fixture whose
 * space cannot be resolved ends the run before any call. The table headers and the score lines are the ones the
 * command printed before it moved into the module.
 */
class AgentDecisionFixturesCommandTest extends UnitTestCase
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
        $asCommandAttributes = (new ReflectionClass(AgentDecisionFixturesCommand::class))->getAttributes(AsCommand::class);
        self::assertSame('app:ai-agent:decision-fixtures', $asCommandAttributes[0]->newInstance()->name);

        $agentDecisionFixturesCommand = new AgentDecisionFixturesCommand();
        self::assertInstanceOf(AgentConsoleCommand::class, $agentDecisionFixturesCommand);
        $commandDefinition = $agentDecisionFixturesCommand->getDefinition();
        self::assertTrue($commandDefinition->getArgument('fixture')->isRequired());
        self::assertSame(['only', 'repeat', 'show-state', AgentConsoleCommand::OPTION_IGNORE_BUDGET, 'without-context'], array_keys($commandDefinition->getOptions()));
        self::assertSame('1', $commandDefinition->getOption('repeat')->getDefault());
    }

    public function testReplyLanguageCasesAreScoredAsTheOwnerUnderAnEvaluationEnvelopePerCall(): void
    {
        $probeCommand = $this->buildProbeCommand();
        $probeCommand->scriptedAnswersByOwnerText['Sigue en español, por favor'] = $this->buildLanguageAnswer('es', 0.95, 'es', 0.95);
        $probeCommand->scriptedAnswersByOwnerText['hello there friend'] = $this->buildLanguageAnswer('en', 0.6, 'none', 0.9);

        $commandTester = new CommandTester($probeCommand);
        $exitCode = $commandTester->execute(['fixture' => $this->writeFixture([
            'site' => 'replyLanguage',
            'agentSpaceId' => AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID,
            'supportedLanguageCodes' => ['en', 'es'],
            'cases' => [
                ['id' => 'switch', 'conversationLanguage' => 'en', 'ownerWords' => 'Sigue en español, por favor', 'expectedWritten' => 'es', 'expectedRequested' => 'es', 'expectedReply' => 'es'],
                ['id' => 'first', 'conversationLanguage' => null, 'ownerWords' => 'hello there friend', 'expectedWritten' => 'fr', 'expectedRequested' => 'none'],
                ['id' => 'broken', 'ownerWords' => 'no answer scripted'],
            ],
        ])]);
        $display = $commandTester->getDisplay();

        self::assertSame(0, $exitCode);
        $ownerAccountId = AgentSpacesServiceConsoleScopeDouble::OWNER_ACCOUNT_ID;
        self::assertSame(
            [
                "Sigue en español, por favor:envelope=open:account={$ownerAccountId}:budget={$ownerAccountId}:billing=none",
                "hello there friend:envelope=open:account={$ownerAccountId}:budget={$ownerAccountId}:billing=none",
                "no answer scripted:envelope=open:account={$ownerAccountId}:budget={$ownerAccountId}:billing=none",
            ],
            $probeCommand->observedCalls
        );
        self::assertCount(3, $this->usageEnvelopesServiceDouble->openedEnvelopeContexts);
        self::assertCount(3, $this->usageEnvelopesServiceDouble->closedEnvelopeContexts, 'the envelope of the failed call is closed too');
        foreach ($this->usageEnvelopesServiceDouble->openedEnvelopeContexts as $openedEnvelopeContext) {
            self::assertSame(AgentAIUsageScope::ENVELOPE_AI_AGENT_EVALUATION, $openedEnvelopeContext->scope);
            self::assertSame($ownerAccountId, $openedEnvelopeContext->accountId);
        }
        self::assertSame('en', $probeCommand->sentDecisions[0]->languageConversationLanguageCode, 'the anchor rides with the context');
        self::assertSame(['en', 'es'], $probeCommand->sentDecisions[0]->supportedLanguageCodes);
        self::assertMatchesRegularExpression('/^\s*case\s+written \(conf\)\s+requested \(conf\)\s+reply\s+anchor\s+latency\s*$/m', $display);
        self::assertMatchesRegularExpression('/^\s*switch\s+es 0\.95\s+es 0\.95\s+es ✓\s+en\s+\d+ ms\s*$/m', $display);
        self::assertMatchesRegularExpression('/^\s*first\s+en 0\.60 ✗ exp fr\s+none 0\.90\s+\S+ ✓\s+—\s+\d+ ms\s*$/m', $display);
        self::assertMatchesRegularExpression('/^\s*broken\s+ERROR ' . AgentDecisionFixturesProbeCommand::CALL_FAILURE_MESSAGE . '\s*$/m', $display);
        self::assertMatchesRegularExpression('/^Arm: with-context · written 1 \/ 2 · requested 2 \/ 2 · REPLY 2 \/ 2 \(100 %\) · median latency \d+ ms$/m', $display);
        self::assertNull(AuthService::instance()->getAccount(), 'the run acted as the owner; the account of before the run is authenticated again');
    }

    public function testWithoutContextTheAnchorAndTheTurnsStayOutOfTheCall(): void
    {
        $probeCommand = $this->buildProbeCommand();
        $probeCommand->scriptedAnswersByOwnerText['Sigue en español, por favor'] = $this->buildLanguageAnswer('es', 0.95, 'es', 0.95);

        $commandTester = new CommandTester($probeCommand);
        $commandTester->execute(['fixture' => $this->writeFixture([
            'site' => 'replyLanguage',
            'agentSpaceId' => AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID,
            'cases' => [['id' => 'switch', 'conversationLanguage' => 'en', 'recentTurns' => ['owner: hi'], 'ownerWords' => 'Sigue en español, por favor']],
        ]), '--without-context' => true]);

        self::assertNull($probeCommand->sentDecisions[0]->languageConversationLanguageCode);
        self::assertSame([], $probeCommand->sentDecisions[0]->languageRecentTurns);
        self::assertSame(AgentDecisionFixturesCommand::DEFAULT_SUPPORTED_LANGUAGE_CODES, $probeCommand->sentDecisions[0]->supportedLanguageCodes, 'a fixture without its own list gets the default');
        self::assertMatchesRegularExpression('/^Arm: without-context · written 1 \/ 1 /m', $commandTester->getDisplay());
    }

    public function testSubSkillPassCasesSendTheLiveSkillsAndScoreThePick(): void
    {
        $probeCommand = $this->buildProbeCommand();
        $pickAnswer = ArgusAgentTurnStartDecision::newInstance();
        $pickAnswer->preloadPickedToken = 'fixture.offered';
        $pickAnswer->preloadPickConfidence = 0.8;
        $pickAnswer->preloadProbabilities = ['none' => 0.1, 'fixture.offered' => 0.8, 'other' => 0.05, 'last' => 0.05];
        $probeCommand->scriptedAnswersByOwnerText['Which part is next?'] = $pickAnswer;

        $commandTester = new CommandTester($probeCommand);
        $exitCode = $commandTester->execute(['fixture' => $this->writeFixture([
            'agentSpaceId' => AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID,
            'cases' => [
                [
                    'id' => 'next-part',
                    'rootSkill' => AISkillsServiceWithFixtureSkillsDouble::ROOT_SKILL_NAME,
                    'loadedSubSkills' => [AISkillsServiceWithFixtureSkillsDouble::LOADED_SUB_SKILL_NAME],
                    'ownerMessage' => 'Which part is next?',
                    'agentSpaceFacts' => 'A space with two parts',
                    'expected' => 'Fixture.Offered',
                ],
                ['id' => 'no-root', 'rootSkill' => 'Fixture.Missing', 'ownerMessage' => 'never sent'],
            ],
        ]), '--repeat' => '2']);
        $display = $commandTester->getDisplay();

        self::assertSame(0, $exitCode);
        self::assertCount(2, $probeCommand->sentDecisions, 'the case is sent twice; the case without a root skill never');
        $sentDecision = $probeCommand->sentDecisions[0];
        self::assertSame(AISkillsServiceWithFixtureSkillsDouble::ROOT_SKILL_NAME, $sentDecision->preloadLoadedRootSkillName);
        self::assertSame("Works on the root's topic.", $sentDecision->preloadRootSkillDescription);
        self::assertSame(['readRootState' => 'Reads the state of the root topic.'], $sentDecision->preloadLoadedRootSkillTools);
        self::assertSame([AISkillsServiceWithFixtureSkillsDouble::LOADED_SUB_SKILL_NAME => 'The loaded part'], $sentDecision->preloadLoadedSubSkills);
        self::assertSame([AISkillsServiceWithFixtureSkillsDouble::OFFERED_SUB_SKILL_NAME => 'Works on the offered part.'], $sentDecision->preloadCatalog);
        self::assertSame('A space with two parts', $sentDecision->preloadAgentSpaceFacts);
        self::assertMatchesRegularExpression('/^\s*case\s+picked \(conf\)\s+expected\s+top-3 · latency\s*$/m', $display);
        self::assertSame(2, preg_match_all('/^\s*next-part\s+fixture\.offered \(0\.80\)\s+fixture\.offered\s+HIT\s+fixture\.offered 0\.80 · none 0\.10 · other 0\.05 · \d+ ms\s*$/m', $display));
        self::assertMatchesRegularExpression('/^\s*no-root\s+root skill not found\s*$/m', $display);
        self::assertStringContainsString('Score: 2 / 2 expected picks hit (100 %).', $display);
        self::assertCount(2, $this->usageEnvelopesServiceDouble->openedEnvelopeContexts);
    }

    public function testAFixtureWhoseSpaceCannotBeResolvedEndsTheRunBeforeAnyCall(): void
    {
        $probeCommand = $this->buildProbeCommand();

        $commandTester = new CommandTester($probeCommand);
        $exitCode = $commandTester->execute(['fixture' => $this->writeFixture([
            'agentSpaceId' => AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID + 1,
            'cases' => [['id' => 'a', 'rootSkill' => AISkillsServiceWithFixtureSkillsDouble::ROOT_SKILL_NAME]],
        ])]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString("The fixture's agentSpaceId or its owning account could not be resolved.", preg_replace('/\s+/', ' ', $commandTester->getDisplay()));
        self::assertSame([], $probeCommand->sentDecisions);
        self::assertSame([], $this->usageEnvelopesServiceDouble->openedEnvelopeContexts);
    }

    public function testAMissingFixtureAndAFixtureWithoutTheNamedCaseEndTheRun(): void
    {
        $commandTester = new CommandTester($this->buildProbeCommand());
        self::assertSame(1, $commandTester->execute(['fixture' => sys_get_temp_dir() . '/agent-decision-fixture-that-does-not-exist.json']));
        self::assertStringContainsString('Fixture not found', $commandTester->getDisplay());

        $commandTester = new CommandTester($this->buildProbeCommand());
        $fixturePath = $this->writeFixture(['agentSpaceId' => AgentSpacesServiceConsoleScopeDouble::STORED_AGENT_SPACE_ID, 'cases' => [['id' => 'a']]]);
        self::assertSame(1, $commandTester->execute(['fixture' => $fixturePath, '--only' => 'b']));
        self::assertStringContainsString('No cases to run.', $commandTester->getDisplay());
    }

    protected function buildProbeCommand(): AgentDecisionFixturesProbeCommand
    {
        return new AgentDecisionFixturesProbeCommand($this->agentSpacesServiceDouble, $this->usageEnvelopesServiceDouble, new AISkillsServiceWithFixtureSkillsDouble());
    }

    protected function buildLanguageAnswer(string $writtenLanguageCode, float $writtenConfidence, string $requestedLanguageCode, float $requestedConfidence): ArgusAgentTurnStartDecision
    {
        $languageAnswer = ArgusAgentTurnStartDecision::newInstance();
        $languageAnswer->writtenLanguageCode = $writtenLanguageCode;
        $languageAnswer->writtenConfidence = $writtenConfidence;
        $languageAnswer->requestedLanguageCode = $requestedLanguageCode;
        $languageAnswer->requestedConfidence = $requestedConfidence;
        return $languageAnswer;
    }

    /** @param array<string, mixed> $fixtureFields */
    protected function writeFixture(array $fixtureFields): string
    {
        $fixturePath = (string)tempnam(sys_get_temp_dir(), 'decision-fixture');
        $this->writtenFilePaths[] = $fixturePath;
        file_put_contents($fixturePath, (string)json_encode($fixtureFields));
        return $fixturePath;
    }
}
