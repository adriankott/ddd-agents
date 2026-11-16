<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeReport;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSettings;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTreeConversation;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalTreeConversations;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\AgentEvalConversationsPurgeCommand;
use DDD\Tests\Agents\Domain\AI\Services\Eval\AIConversationEvalPurgeServiceRecordingDouble;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The purge command keeps the name and the options a cron row calls it with, and prints what the purge reported in
 * the format its readers know: the accounts line, one progress line per batch, the step table (table or `table.column
 * = NULL`, rows, estimated MB, the total), the summary table with its 17 names in their order, the account table, the
 * sample table and the result line. The expected output is the format the command has always printed, written out
 * from its table headers, cell rules and line texts.
 */
class AgentEvalConversationsPurgeCommandTest extends UnitTestCase
{
    /** @var string[] The options of the command, in their order */
    public const array OPTION_NAMES = [
        'older-than-hours', 'fresh-heartbeat-minutes', 'trees-per-batch', 'heavy-rows-per-statement', 'light-rows-per-statement',
        'pause-ms', 'slow-write-ms', 'max-seconds', 'max-threads-running', 'max-history-list-length', 'after-root-conversation-id',
        'only-root-conversation-id', 'accounts', 'evalOnly', 'apply',
    ];

    protected static function buildScriptedPurgeService(): AIConversationEvalPurgeServiceRecordingDouble
    {
        $scriptedPurgeService = new AIConversationEvalPurgeServiceRecordingDouble();
        $scriptedPurgeService->scriptedCandidateBatches = [[10, 20, 30]];
        $scriptedPurgeService->treeConversationsByRootConversationId = [
            10 => self::buildTreeConversations(10, 7001, '2026-03-01 08:00:00'),
            20 => self::buildTreeConversations(20, 7002, '2026-03-11 08:00:00'),
            30 => self::buildTreeConversations(30, 9999, '2026-03-01 08:00:00'),
        ];
        $scriptedPurgeService->scriptedIdsByTableColumn = [DBAIConversationMessageModel::getTableName() . '.aiConversationId' => [100, 101, 102]];
        $scriptedPurgeService->scriptedAverageRowBytesByTableName = [DBAIConversationMessageModel::getTableName() => AIConversationEvalPurgeReport::BYTES_PER_MEGABYTE];
        $scriptedPurgeService->scriptedEvalMarkersByRootConversationId = [10 => 'evalRun'];
        return $scriptedPurgeService;
    }

    protected static function buildTreeConversations(int $rootConversationId, int $accountId, string $created): AIConversationEvalTreeConversations
    {
        $treeConversations = AIConversationEvalTreeConversations::newInstance();
        foreach ([$rootConversationId, $rootConversationId + 1] as $conversationId) {
            $treeConversation = AIConversationEvalTreeConversation::forConversation($conversationId, $accountId, AIConversationRunState::COMPLETED, false);
            $treeConversation->setActivityMoment('created', $created);
            $treeConversations->add($treeConversation);
        }
        return $treeConversations;
    }

    public function testTheCommandKeepsItsNameAndOptions(): void
    {
        $asCommandAttributes = (new ReflectionClass(AgentEvalConversationsPurgeCommand::class))->getAttributes(AsCommand::class);
        self::assertCount(1, $asCommandAttributes);
        self::assertSame('app:ai-agent:eval-conversations-purge', $asCommandAttributes[0]->newInstance()->name);

        $agentEvalConversationsPurgeCommand = new AgentEvalConversationsPurgeCommand();
        self::assertInstanceOf(AgentConsoleCommand::class, $agentEvalConversationsPurgeCommand);
        $commandDefinition = $agentEvalConversationsPurgeCommand->getDefinition();
        self::assertSame(self::OPTION_NAMES, array_keys($commandDefinition->getOptions()));
        $settingsDefaults = AIConversationEvalPurgeSettings::newInstance();
        self::assertSame((string)$settingsDefaults->olderThanHours, $commandDefinition->getOption('older-than-hours')->getDefault());
        self::assertSame('48', $commandDefinition->getOption('older-than-hours')->getDefault());
        self::assertSame((string)AIConversationEvalPurgeSettings::MAXIMUM_SECONDS, $commandDefinition->getOption('max-seconds')->getDefault());
        self::assertFalse($commandDefinition->getOption('apply')->acceptValue(), 'a flag: without it the run only counts');
    }

    public function testADryRunPrintsTheAccountsTheProgressTheFourTablesAndTheResultLine(): void
    {
        $commandTester = new CommandTester(new AgentEvalConversationsPurgeProbeCommand(self::buildScriptedPurgeService()));

        self::assertSame(Command::SUCCESS, $commandTester->execute(['--evalOnly' => true]));
        self::assertSame(<<<'DISPLAY'
[dry run] accounts: 7001, 7002 — eval-marked conversation trees only
[dry run] roots up to 30: 3 trees checked, 1 purged (2 conversations), 30 rows, 3 MB, average write - ms
+--------------------------------------------------------+--------------+--------------+
| table / column                                         | rows (would) | estimated MB |
+--------------------------------------------------------+--------------+--------------+
| AIConversationTurnEvents                               | 2            | 0            |
| AIConversationTraceEvents                              | 2            | 0            |
| AIConversationToolCallBatches                          | 2            | 0            |
| AIConversationDelegationBatches                        | 2            | 0            |
| AIConversationTaskPushNotificationConfigs              | 2            | 0            |
| AIConversationCompactionNodes                          | 2            | 0            |
| AgentMandates                                          | 2            | 0            |
| AIConversationSchedules.createdByConversationId = NULL | 2            | -            |
| AgentMandates.sourceUserMessageId = NULL               | 3            | -            |
| AgentMandates.revokedByMessageId = NULL                | 3            | -            |
| AIConversationMessageEmbeddings                        | 3            | 0            |
| AIConversationMessages                                 | 3            | 3            |
| AIConversations                                        | 2            | 0            |
| TOTAL                                                  | 30           | 3            |
+--------------------------------------------------------+--------------+--------------+
+----------------------------+--------------------------------+
|                            | value                          |
+----------------------------+--------------------------------+
| activityCutoff (UTC)       | 2026-03-10 12:00:00            |
| treesChecked               | 3                              |
| treesPurged                | 1                              |
| conversationsInPurgedTrees | 2                              |
| treesSkippedRunInFlight    | 0                              |
| treesSkippedFreshHeartbeat | 0                              |
| treesSkippedRecentActivity | 1                              |
| treesSkippedNotEvalAccount | 1                              |
| batchesLeftIncomplete      | 0                              |
| chunksSkippedOnLock        | 0                              |
| pausesForServerLoad        | 0                              |
| writes                     | 0                              |
| averageWriteMs             | -                              |
| slowestWriteMs             | -                              |
| lastRootConversationId     | 30                             |
| runSeconds                 | 0                              |
| stoppedBecause             | no eval conversation tree left |
+----------------------------+--------------------------------+
+---------+---------------+---------------+
| account | trees (would) | conversations |
+---------+---------------+---------------+
| 7001    | 1             | 2             |
+---------+---------------+---------------+
+--------------------------+---------+-----------------------+-------------+
| sample root conversation | account | conversations in tree | eval marker |
+--------------------------+---------+-----------------------+-------------+
| 10                       | 7001    | 2                     | evalRun     |
+--------------------------+---------+-----------------------+-------------+

 [OK] dry run: 1 trees, 30 rows, stopped because: no eval conversation tree left


DISPLAY, $commandTester->getDisplay(true));
    }

    public function testTheOptionsBecomeTheSettingsOfTheRun(): void
    {
        $scriptedPurgeService = self::buildScriptedPurgeService();
        $scriptedPurgeService->scriptedCandidateBatches = [];
        $commandTester = new CommandTester(new AgentEvalConversationsPurgeProbeCommand($scriptedPurgeService));

        $commandTester->execute(['--apply' => true, '--older-than-hours' => '0', '--accounts' => '7002, x,7002', '--after-root-conversation-id' => '41']);

        self::assertSame([['accountIds' => [7002], 'activityCutoff' => '2026-03-12 11:00:00', 'afterRootConversationId' => 41]], $scriptedPurgeService->candidateReads, 'an age below one hour counts as one hour');
        self::assertStringStartsWith("accounts: 7002\n", $commandTester->getDisplay(true), 'a writing run carries no dry-run mark');
        self::assertStringContainsString('| table / column | rows | estimated MB |', $commandTester->getDisplay(true));
        self::assertStringContainsString('[OK] 0 trees, 0 rows, stopped because: no eval conversation tree left', $commandTester->getDisplay(true));
    }

    public function testARefusedAccountEndsTheRunWithTheReasonAndWithoutARead(): void
    {
        $scriptedPurgeService = self::buildScriptedPurgeService();
        $commandTester = new CommandTester(new AgentEvalConversationsPurgeProbeCommand($scriptedPurgeService));

        self::assertSame(Command::FAILURE, $commandTester->execute(['--accounts' => '9001']));
        self::assertStringContainsString('[ERROR] Not a configured eval account: 9001 — its conversations are not purged.', $commandTester->getDisplay(true));
        self::assertSame([], $scriptedPurgeService->candidateReads);
    }
}
