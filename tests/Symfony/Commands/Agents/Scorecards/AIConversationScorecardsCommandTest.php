<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Scorecards;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Conversations\Metrics\AIConversationMetrics;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\AIConversationScorecardsCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The scorecard command without an application's quality component: the options name the selection the scorecards
 * service reads; a conversation that is no scored root, or an eval run without `--eval-runs`, is skipped; `--print`
 * prints the metrics with the module's columns; the default run dispatches one message per conversation; `--inline`
 * writes the scorecard under the compaction envelope as the worker does; `--missing` finds nothing and respects the
 * queue; `--shard` keeps the ids of its hash and an unreadable shard keeps every id; a conversation that fails is
 * printed as a warning and the run goes on.
 */
class AIConversationScorecardsCommandTest extends UnitTestCase
{
    public function testTheCommandKeepsItsNameAndItsOptionsAndRunsOnTheBase(): void
    {
        $asCommandAttributes = (new ReflectionClass(AIConversationScorecardsCommand::class))->getAttributes(AsCommand::class);
        self::assertSame('app:ai-conversation:scorecards', $asCommandAttributes[0]->newInstance()->name);

        $aIConversationScorecardsCommand = new AIConversationScorecardsCommand();
        self::assertInstanceOf(AgentConsoleCommand::class, $aIConversationScorecardsCommand);
        self::assertSame(
            ['since', 'conversation', 'purpose', 'missing', 'print', 'force', 'eval-runs', 'internal-accounts', 'inline', 'limit', 'shard'],
            array_keys($aIConversationScorecardsCommand->getDefinition()->getOptions())
        );
        self::assertSame((string)AIConversationScorecardsCommand::DEFAULT_LIMIT, $aIConversationScorecardsCommand->getDefinition()->getOption('limit')->getDefault());
    }

    public function testPrintPrintsTheMetricsOfEveryScoredRootAndSkipsTheRest(): void
    {
        $probeCommand = new AIConversationScorecardsProbeCommand();
        $this->scriptConversation($probeCommand, 1, purposeName: 'Intake.General', runState: AIConversationRunState::COMPLETED, initiatorType: AIConversation::INITIATOR_TYPE_PHONE_CALL);
        $this->scriptConversation($probeCommand, 2, delegatedFromMessageId: 77);
        $this->scriptConversation($probeCommand, 3, isEvalRun: true);
        $this->scriptConversation($probeCommand, 4, purposeName: AIConversationPurpose::SUPERVISOR_CHECKPOINT);
        $probeCommand->metricsServiceDouble->scriptedMetricsByConversationId[1] = $this->buildMetrics();

        $commandTester = new CommandTester($probeCommand);
        $exitCode = $commandTester->execute(['--print' => true, '--conversation' => '1,2,3,4,5']);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertMatchesRegularExpression('/conversation\s+purpose\s+entry\s+initiator\s+eval\s+run state\s+wall s/', $display);
        self::assertMatchesRegularExpression(
            '/ 1\s+Intake\.General\s+ownerMessage\s+PHONE_CALL\s+COMPLETED\s+125\s+60\s+40\s+15\s+5\s+10\s+12 \(2\)\s+3\s+3 \/ 9\s+3 \(2\)\s+4 /',
            $display
        );
        self::assertSame(1, preg_match_all('/^\s+\d+\s{2,}/m', $display), 'one printed row: the delegation, the eval run, the hidden purpose and the unknown id are skipped');
        self::assertSame([], $probeCommand->scorecardsServiceDouble->askedSelections, '--conversation names the ids; no selection is read');
        self::assertSame([], $probeCommand->forceAnalysisByDispatchedConversationId, 'a printing run dispatches nothing');
    }

    public function testWithEvalRunsTheEvalRunIsPrintedAndMarked(): void
    {
        $probeCommand = new AIConversationScorecardsProbeCommand();
        $this->scriptConversation($probeCommand, 3, isEvalRun: true);

        $commandTester = new CommandTester($probeCommand);
        $commandTester->execute(['--print' => true, '--conversation' => '3', '--eval-runs' => true]);

        self::assertMatchesRegularExpression('/ 3\s+yes\s+IDLE /', $commandTester->getDisplay(), 'no purpose, no metrics, the eval marker and the run state');
    }

    public function testTheOptionsNameTheSelectionTheScorecardsServiceReads(): void
    {
        $probeCommand = new AIConversationScorecardsProbeCommand();

        $commandTester = new CommandTester($probeCommand);
        $exitCode = $commandTester->execute([
            '--since' => '2026-01-01',
            '--purpose' => 'none, Intake.General,Intake.General',
            '--eval-runs' => true,
            '--limit' => '7',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No conversation in the selection.', $commandTester->getDisplay());
        self::assertCount(1, $probeCommand->scorecardsServiceDouble->askedSelections);
        $askedSelection = $probeCommand->scorecardsServiceDouble->askedSelections[0];
        self::assertSame('2026-01-01', $askedSelection->createdSince);
        self::assertTrue($askedSelection->isRestrictedByPurpose);
        self::assertSame(['Intake.General'], $askedSelection->purposeNames);
        self::assertTrue($askedSelection->includesConversationsWithoutPurpose);
        self::assertTrue($askedSelection->includesEvalRuns);
        self::assertFalse($askedSelection->includesInternalAccounts);
        self::assertSame(7, $askedSelection->limit);

        (new CommandTester($probeCommand))->execute(['--internal-accounts' => true]);
        $defaultSelection = $probeCommand->scorecardsServiceDouble->askedSelections[1];
        self::assertFalse($defaultSelection->isRestrictedByPurpose, 'without --purpose every purpose and the regular chats');
        self::assertFalse($defaultSelection->includesEvalRuns);
        self::assertTrue($defaultSelection->includesInternalAccounts);
        self::assertSame(AIConversationScorecardsCommand::DEFAULT_LIMIT, $defaultSelection->limit);
        self::assertSame(date('Y-m-d', strtotime('-30 days')), $defaultSelection->createdSince);
    }

    public function testTheDefaultRunDispatchesOneMessagePerScoredConversation(): void
    {
        $probeCommand = new AIConversationScorecardsProbeCommand();
        $probeCommand->scorecardsServiceDouble->selectedConversationIds = [1, 2];
        $this->scriptConversation($probeCommand, 1);
        $this->scriptConversation($probeCommand, 2, delegatedFromMessageId: 77);

        $commandTester = new CommandTester($probeCommand);
        $commandTester->execute(['--force' => true]);

        self::assertSame([1 => true], $probeCommand->forceAnalysisByDispatchedConversationId);
        self::assertSame([], $probeCommand->scorecardsServiceDouble->forceAnalysisByWrittenConversationId, 'the worker writes, not this process');
        self::assertStringContainsString(
            '1 scorecards dispatched to the workers, 1 skipped (hidden, delegation or eval runs — --eval-runs includes the eval runs).',
            preg_replace('/\s+/', ' ', $commandTester->getDisplay())
        );
    }

    public function testInlineWritesTheScorecardUnderTheCompactionEnvelopeAndCountsTheOutcome(): void
    {
        $probeCommand = new AIConversationScorecardsProbeCommand();
        $this->scriptConversation($probeCommand, 1, accountId: 42);
        $this->scriptConversation($probeCommand, 2, accountId: 43);

        $commandTester = new CommandTester($probeCommand);
        $commandTester->execute(['--inline' => true, '--force' => true, '--conversation' => '1,2']);

        self::assertSame([1 => true, 2 => true], $probeCommand->scorecardsServiceDouble->forceAnalysisByWrittenConversationId);
        $openedEnvelopeContexts = $probeCommand->usageEnvelopesServiceDouble->openedEnvelopeContexts;
        self::assertCount(2, $openedEnvelopeContexts);
        self::assertSame(AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_COMPACTION, $openedEnvelopeContexts[0]->scope);
        self::assertSame(AIUsageInitiator::ACCOUNT, $openedEnvelopeContexts[0]->initiatedBy);
        self::assertSame([42, 43], [$openedEnvelopeContexts[0]->accountId, $openedEnvelopeContexts[1]->accountId]);
        self::assertCount(2, $probeCommand->usageEnvelopesServiceDouble->closedEnvelopeContexts, 'every envelope is closed');
        self::assertStringContainsString('2 scorecards written, 0 skipped', preg_replace('/\s+/', ' ', $commandTester->getDisplay()));
        self::assertStringContainsString('Analysis: done 2.', preg_replace('/\s+/', ' ', $commandTester->getDisplay()));
    }

    public function testMissingFindsNothingWithoutAQualityComponentAndLeavesAFullQueueAlone(): void
    {
        $fullQueueCommand = new AIConversationScorecardsProbeCommand(new ScorecardTransportWithQueuedCountDouble(500));
        $fullQueueTester = new CommandTester($fullQueueCommand);
        $fullQueueTester->execute(['--missing' => true]);
        self::assertStringContainsString('500 scorecards still queued — nothing dispatched.', $fullQueueTester->getDisplay());

        $openQueueCommand = new AIConversationScorecardsProbeCommand(new ScorecardTransportWithQueuedCountDouble(3));
        $openQueueTester = new CommandTester($openQueueCommand);
        $openQueueTester->execute(['--missing' => true]);
        self::assertStringContainsString('No conversation in the selection.', $openQueueTester->getDisplay());
        self::assertSame([], $openQueueCommand->scorecardsServiceDouble->askedSelections, '--missing reads no date-window selection');

        $withoutTransportTester = new CommandTester(new AIConversationScorecardsProbeCommand());
        $withoutTransportTester->execute(['--missing' => true, '--limit' => '1']);
        self::assertStringContainsString('No conversation in the selection.', $withoutTransportTester->getDisplay(), 'no transport counts as an empty queue');
    }

    public function testTheShardKeepsTheIdsOfItsHash(): void
    {
        $probeCommand = new AIConversationScorecardsProbeCommand();
        $conversationIds = range(2, 42, 4);
        foreach ($conversationIds as $conversationId) {
            $this->scriptConversation($probeCommand, $conversationId);
        }
        $probeCommand->scorecardsServiceDouble->selectedConversationIds = $conversationIds;

        (new CommandTester($probeCommand))->execute(['--shard' => '1/3']);

        $expectedConversationIds = array_values(array_filter($conversationIds, static fn (int $conversationId): bool => crc32((string)$conversationId) % 3 === 1));
        self::assertNotSame([], $expectedConversationIds);
        self::assertSame($expectedConversationIds, array_keys($probeCommand->forceAnalysisByDispatchedConversationId));
    }

    public function testAnUnreadableShardKeepsEveryId(): void
    {
        foreach (['abc', '1/0', '1-3'] as $unreadableShard) {
            $probeCommand = new AIConversationScorecardsProbeCommand();
            foreach ([2, 6, 10] as $conversationId) {
                $this->scriptConversation($probeCommand, $conversationId);
            }
            $probeCommand->scorecardsServiceDouble->selectedConversationIds = [2, 6, 10];

            (new CommandTester($probeCommand))->execute(['--shard' => $unreadableShard]);

            self::assertSame([2, 6, 10], array_keys($probeCommand->forceAnalysisByDispatchedConversationId), $unreadableShard);
        }
    }

    public function testAConversationThatFailsIsPrintedAsAWarningAndTheRunGoesOn(): void
    {
        $probeCommand = new AIConversationScorecardsProbeCommand();
        $this->scriptConversation($probeCommand, 1);
        $this->scriptConversation($probeCommand, 3);
        $probeCommand->conversationsServiceDouble->failingConversationIds = [2];

        $commandTester = new CommandTester($probeCommand);
        $exitCode = $commandTester->execute(['--conversation' => '1,2,3']);
        $display = (string)preg_replace('/\s+/', ' ', $commandTester->getDisplay());

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('[WARNING] Conversation 2: ' . ConversationsServiceWithScriptedConversationsByIdDouble::LOOKUP_FAILURE_MESSAGE, $display);
        self::assertSame([1, 3], array_keys($probeCommand->forceAnalysisByDispatchedConversationId));
        self::assertStringContainsString('2 scorecards dispatched to the workers, 0 skipped', $display, 'the failed conversation is neither written nor skipped');
    }

    protected function scriptConversation(
        AIConversationScorecardsProbeCommand $probeCommand,
        int $conversationId,
        ?string $purposeName = null,
        string $runState = AIConversationRunState::IDLE,
        ?string $initiatorType = null,
        ?int $delegatedFromMessageId = null,
        bool $isEvalRun = false,
        ?int $accountId = null,
    ): void {
        $conversation = new AIConversation();
        $conversation->id = $conversationId;
        $conversation->purposeName = $purposeName;
        $conversation->runState = $runState;
        $conversation->initiatorType = $initiatorType;
        $conversation->delegatedFromMessageId = $delegatedFromMessageId;
        $conversation->accountId = $accountId;
        $conversation->account = null;
        if ($isEvalRun) {
            $runSettings = new RunSettings();
            $runSettings->evalRun = true;
            $conversation->runSettings = $runSettings;
        }
        $probeCommand->conversationsServiceDouble->scriptedConversationsById[$conversationId] = $conversation;
    }

    protected function buildMetrics(): AIConversationMetrics
    {
        $metrics = new AIConversationMetrics();
        $metrics->entryPoint = AIConversationMetrics::ENTRY_POINT_OWNER_MESSAGE;
        $metrics->totalWallSeconds = 125.4;
        $metrics->activeWallSeconds = 60.2;
        $metrics->agentSeconds = 40.0;
        $metrics->ownerSeconds = 15.0;
        $metrics->awaySeconds = 5.0;
        $metrics->idleSeconds = 10.0;
        $metrics->modelSeconds = 12.0;
        $metrics->modelCallsWithoutEgressStamp = 2;
        $metrics->platformOverheadSeconds = 3.0;
        $metrics->p50TurnAgentSeconds = 3.2;
        $metrics->p90TurnAgentSeconds = 8.7;
        $metrics->ownerTurns = 3;
        $metrics->ownerTextTurns = 2;
        $metrics->greetingLatencySeconds = 4.4;
        return $metrics;
    }
}
