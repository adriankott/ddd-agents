<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Evals;

use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonRecording;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Repo\DB\Conversations\TraceEvents\DBAIConversationTraceEventModel;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEventSnapshotModel;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Base\Repo\DB\Dialect\Write\PostgresIdentityCatchUp;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Services\AuthService;
use DDD\Tests\Agents\Fixtures\ToolCallComparisons\Host\Domain\ComparisonSnapshotKeptMessage;
use DDD\Tests\Agents\Fixtures\ToolCallComparisons\Host\Presentation\Mcp\Notes\ComparisonNotesMCPPackage;
use DDD\Tests\Agents\Support\DatabaseTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The tool-call comparison end to end on the package kernel, on MariaDB and on PostgreSQL: the fixture script of
 * `tests/Fixtures/ToolCallComparisons/Scripts` is recorded (its harness conversation and owner row are stored, the
 * statement audit, the dispatch capture, the id marks and the pinned clock run for real), replayed identically on the
 * same build, and a replay after a changed tool result lists the difference and exits 1. The fixture tools make no
 * Argus call, so no transport is played.
 *
 * The case `notes.snapshot` writes a row into a table without a scope column (the turn-event snapshots: its id reads
 * as a placeholder only because the audited INSERT names that table — real catalog name, quoted by the engine), a row
 * read through the conversation's id (a trace event: the scoped read of the minted ids), and dispatches a message
 * naming both ids (kept by the capture middleware of the package kernel, never sent). The snapshot's hash comes from
 * the pinned id source and the trace event's key is fixed, so each run's two rows are deleted before the next run
 * writes the same hash and the same key again.
 *
 * Every table starts a test with its id counter at 1. The comparison names an id by its number: the harness rows are
 * declared by their id, a later declaration of the same number replaces the earlier one, and a minted id whose number
 * a harness row carries reads as that harness row. Without a preparation the harness conversation, its owner row, the
 * first snapshot row and the first trace event would all carry the id 1: the conversation would read as its own owner
 * row and the result would name that row twice. {@see self::letTheTablesOfTheRunCountFromDifferentIds()} therefore
 * gives each of the four tables its own range of ids, as the tables of an application in use have.
 */
class MCPCompareToolCallsRecordAndReplayDatabaseTest extends DatabaseTestCase
{
    public const int COMPARISON_ACCOUNT_ID = 501;

    /** @var int The id of the first conversation a test of this class stores */
    public const int FIRST_CONVERSATION_ID = 21;

    /** @var int The id of the first conversation message a test of this class stores */
    public const int FIRST_CONVERSATION_MESSAGE_ID = 301;

    /** @var int The id of the first turn-event snapshot a test of this class stores */
    public const int FIRST_SNAPSHOT_ID = 4001;

    /** @var int The id of the first trace event a test of this class stores */
    public const int FIRST_TRACE_EVENT_ID = 7001;

    protected ?Account $accountBefore = null;

    protected string $recordingDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBefore = AuthService::instance()->getAccount();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::COMPARISON_ACCOUNT_ID, 'email' => 'comparison@example.test']);
        $this->recordingDirectory = sys_get_temp_dir() . '/tool-call-comparison-' . bin2hex(random_bytes(4));
        ComparisonNotesMCPPackage::$resultPrefix = 'recorded: ';
        $this->letTheTablesOfTheRunCountFromDifferentIds();
    }

    protected function tearDown(): void
    {
        ComparisonNotesMCPPackage::$resultPrefix = 'recorded: ';
        foreach (glob($this->recordingDirectory . '/*') ?: [] as $recordingFile) {
            @unlink($recordingFile);
        }
        @rmdir($this->recordingDirectory);
        AuthService::instance()->setAccount($this->accountBefore);
        parent::tearDown();
    }

    public function testARecordingReplaysIdenticallyAndAChangedResultIsADifference(): void
    {
        $recordingPath = $this->recordingDirectory . '/ComparisonNotesMCPPackage.json';

        $recordTester = $this->runComparison(['--script' => 'ComparisonNotesMCPPackage', '--record' => $recordingPath]);
        self::assertSame(Command::SUCCESS, $recordTester->getStatusCode(), $recordTester->getDisplay());
        self::assertStringContainsString('recorded 3 cases of ComparisonNotesMCPPackage.json (0 Argus calls)', $recordTester->getDisplay());
        self::assertFileExists($recordingPath . '.definitions.json');
        $recording = ToolCallComparisonRecording::fromJson((string)file_get_contents($recordingPath), $recordingPath);
        self::assertSame(['notes.record', 'notes.snapshot', 'archive.read'], array_map(static fn ($caseOutcome): string => $caseOutcome->caseId, array_values($recording->caseOutcomes->getElements())));
        self::assertArrayHasKey('<conversation:chat>', $recording->harnessIdsByPlaceholder);
        $this->assertTheSnapshotCaseReadsItsRowsAsPlaceholders($recording);

        $this->deleteTheRowsOfTheSnapshotCase();
        $replayTester = $this->runComparison(['--script' => 'ComparisonNotesMCPPackage', '--replay-and-compare' => $recordingPath]);
        self::assertSame(Command::SUCCESS, $replayTester->getStatusCode(), $replayTester->getDisplay());
        self::assertStringContainsString('tool-call comparison IDENTICAL: 3 cases of ComparisonNotesMCPPackage.json, definitions byte-identical', $replayTester->getDisplay());

        $this->deleteTheRowsOfTheSnapshotCase();
        ComparisonNotesMCPPackage::$resultPrefix = 'kept: ';
        $changedReplayTester = $this->runComparison(['--script' => 'ComparisonNotesMCPPackage', '--replay-and-compare' => $recordingPath]);
        self::assertSame(Command::FAILURE, $changedReplayTester->getStatusCode());
        self::assertStringContainsString('notes.record: 1 difference(s)', $changedReplayTester->getDisplay());
        self::assertStringContainsString('notes.snapshot: identical', $changedReplayTester->getDisplay());
        self::assertStringContainsString('archive.read: identical', $changedReplayTester->getDisplay());
        self::assertStringContainsString('tool-call comparison DIFFERS: 1 difference(s) in ComparisonNotesMCPPackage.json', $changedReplayTester->getDisplay());
    }

    /**
     * The stored outcome of `notes.snapshot`: the result names both rows by placeholder, the INSERT into the snapshot
     * table is among the statements (its table name as the catalog has it), the trace event's conversation reads as the
     * harness conversation, and the one captured dispatch names both rows by placeholder.
     */
    protected function assertTheSnapshotCaseReadsItsRowsAsPlaceholders(ToolCallComparisonRecording $recording): void
    {
        $snapshotTable = DBAIConversationTurnEventSnapshotModel::getTableName();
        $traceEventTable = DBAIConversationTraceEventModel::getTableName();
        $storedOutcome = $recording->caseOutcomes->findOutcomeOfCase('notes.snapshot')?->toArray() ?? [];

        self::assertStringContainsString("snapshot #<{$snapshotTable}#1>, trace event #<{$traceEventTable}#1>", (string)($storedOutcome['resultContent'] ?? ''));
        $insertedTables = [];
        foreach ((array)($storedOutcome['writeStatements'] ?? []) as $writeStatement) {
            if (($writeStatement['kind'] ?? null) === 'INSERT') {
                $insertedTables[] = (string)($writeStatement['table'] ?? '');
            }
        }
        self::assertContains($snapshotTable, $insertedTables);
        self::assertContains($traceEventTable, $insertedTables);
        self::assertStringContainsString('"aiConversationId":"<conversation:chat>"', (string)json_encode($storedOutcome['writeStatements'] ?? []));
        self::assertSame([[
            'messageClass' => ComparisonSnapshotKeptMessage::class,
            'body' => ['snapshotId' => "<{$snapshotTable}#1>", 'traceEventId' => "<{$traceEventTable}#1>"],
        ]], $storedOutcome['dispatches'] ?? null);
    }

    /**
     * Gives the four tables the run writes — the conversations, their messages, the turn-event snapshots and the trace
     * events — ranges of ids that do not overlap, so no two rows of the run carry the same number.
     */
    protected function letTheTablesOfTheRunCountFromDifferentIds(): void
    {
        $this->letTheIdsOfTableStartAt(DBAIConversationModel::getTableName(), self::FIRST_CONVERSATION_ID);
        $this->letTheIdsOfTableStartAt(DBAIConversationMessageModel::getTableName(), self::FIRST_CONVERSATION_MESSAGE_ID);
        $this->letTheIdsOfTableStartAt(DBAIConversationTurnEventSnapshotModel::getTableName(), self::FIRST_SNAPSHOT_ID);
        $this->letTheIdsOfTableStartAt(DBAIConversationTraceEventModel::getTableName(), self::FIRST_TRACE_EVENT_ID);
    }

    /**
     * Moves the id counter of one empty table: the next row stored without an id gets `$firstId`. MariaDB: the table's
     * AUTO_INCREMENT value; PostgreSQL: the identity sequence, advanced with `nextval()` by the framework's catch-up
     * (the one it runs after a row was stored with an id of its own). The emptying of the tables before the next test
     * sets both back.
     */
    protected function letTheIdsOfTableStartAt(string $tableName, int $firstId): void
    {
        $connection = $this->getDatabaseConnection();
        if ($this->isPostgres()) {
            PostgresIdentityCatchUp::afterExplicitIdInsert($connection, $tableName, $firstId - 1);
            return;
        }
        $connection->executeStatement('ALTER TABLE ' . $connection->quoteIdentifier($tableName) . ' AUTO_INCREMENT = ' . $firstId);
    }

    /**
     * Deletes every snapshot row and every trace event: the next run writes the same pinned hash and the same event
     * key, which each table holds once.
     */
    protected function deleteTheRowsOfTheSnapshotCase(): void
    {
        $connection = $this->getDatabaseConnection();
        $connection->executeStatement('DELETE FROM ' . $connection->quoteIdentifier(DBAIConversationTurnEventSnapshotModel::getTableName()));
        $connection->executeStatement('DELETE FROM ' . $connection->quoteIdentifier(DBAIConversationTraceEventModel::getTableName()));
    }

    /** @param array<string, string> $commandInput */
    protected function runComparison(array $commandInput): CommandTester
    {
        $comparisonAccount = new Account();
        $comparisonAccount->id = self::COMPARISON_ACCOUNT_ID;
        $mCPCompareToolCallsCommand = new MCPCompareToolCallsStoredAccountProbeCommand();
        $mCPCompareToolCallsCommand->comparisonAccount = $comparisonAccount;
        $commandTester = new CommandTester($mCPCompareToolCallsCommand);
        $commandTester->execute($commandInput);
        return $commandTester;
    }
}
