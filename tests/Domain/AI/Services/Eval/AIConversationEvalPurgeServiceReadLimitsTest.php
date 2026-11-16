<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeKeys;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSettings;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeStep;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSteps;
use DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService;
use DDD\Tests\Agents\Support\UnitTestCase;
use RuntimeException;

/**
 * Every read statement of the evaluation purge runs between {@see AIConversationEvalPurgeService::beginStatementLimitedRead()}
 * and {@see AIConversationEvalPurgeService::endStatementLimitedRead()}: in a transaction of its own with the statement
 * limit, and nothing stays on the connection afterwards — also when the read fails, and never touching a transaction
 * of the caller.
 */
class AIConversationEvalPurgeServiceReadLimitsTest extends UnitTestCase
{
    /** @var string[] The calls around one read on MariaDB before the statement */
    protected const array MARIADB_CALLS_BEFORE_A_READ = ['begin', 'SET SESSION max_statement_time = ' . AIConversationEvalPurgeService::STATEMENT_TIMEOUT_SECONDS];

    /** @var string[] The calls around one read on MariaDB after the statement */
    protected const array MARIADB_CALLS_AFTER_A_READ = ['commit', 'SET SESSION innodb_lock_wait_timeout = DEFAULT', 'SET SESSION max_statement_time = DEFAULT'];

    public function testEveryReadSiteRunsBetweenABeginAndAnEnd(): void
    {
        $service = new AIConversationEvalPurgeServiceOnARecordingConnection();
        $settings = AIConversationEvalPurgeSettings::newInstance();
        $settings->dryRun = true;
        $step = AIConversationEvalPurgeStep::deleteRows('SomeTable', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION);
        $steps = AIConversationEvalPurgeSteps::newInstance();
        $steps->add($step);

        $service->findCandidates([7001], $settings);
        $service->findEvalTree(10, $settings);
        $service->findMarkers([10]);
        $service->findIdsWhereColumnIn('SomeTable', 'aiConversationId', [10, 11], $settings);
        $service->countRowsOfStep($step, [10]);
        $service->findAverageRowBytesByTableName($steps);

        $readStatements = self::assertEveryReadIsBracketed($service->recordingConnection->recordedCalls);
        self::assertCount(8, $readStatements, 'candidates, tree expansion, tree rows, newest message, markers, ids, count, statistics');
        self::assertStringContainsString('ORDER BY', $readStatements[0]);
        self::assertStringStartsWith('SELECT COUNT(*) FROM `SomeTable`', $readStatements[6]);
        self::assertStringContainsString('information_schema', $readStatements[7]);
        self::assertFalse($service->recordingConnection->isTransactionActive());
    }

    public function testOnPostgresTheLimitIsSetForTheReadsTransactionOnly(): void
    {
        $service = new AIConversationEvalPurgeServiceOnARecordingConnection();
        $service->databaseDriver = 'pdo_pgsql';

        $service->findIdsWhereColumnIn('SomeTable', 'aiConversationId', [10], AIConversationEvalPurgeSettings::newInstance());

        self::assertSame(
            ['begin', 'SET LOCAL statement_timeout = ' . AIConversationEvalPurgeService::STATEMENT_TIMEOUT_SECONDS * 1000, 'SELECT "id" FROM "SomeTable" WHERE "aiConversationId" IN (:columnValues)', 'commit'],
            $service->recordingConnection->recordedCalls
        );
    }

    public function testAFailedReadStillEndsItsTransactionAndClearsTheLimits(): void
    {
        $service = new AIConversationEvalPurgeServiceOnARecordingConnection();
        $service->recordingConnection->readError = new RuntimeException('the read failed');

        try {
            $service->findIdsWhereColumnIn('SomeTable', 'aiConversationId', [10], AIConversationEvalPurgeSettings::newInstance());
            self::fail('the read error reaches the caller');
        } catch (RuntimeException $readError) {
            self::assertSame('the read failed', $readError->getMessage());
        }

        self::assertSame(self::MARIADB_CALLS_AFTER_A_READ, array_slice($service->recordingConnection->recordedCalls, 3));
        self::assertFalse($service->recordingConnection->isTransactionActive());
    }

    public function testTheBracketNeverTouchesATransactionOfTheCaller(): void
    {
        $service = new AIConversationEvalPurgeServiceOnARecordingConnection();
        $service->recordingConnection->beginTransaction();

        $service->endStatementLimitedRead();
        self::assertTrue($service->recordingConnection->isTransactionActive(), 'an end without a begin leaves the caller\'s transaction open');

        $service->findIdsWhereColumnIn('SomeTable', 'aiConversationId', [10], AIConversationEvalPurgeSettings::newInstance());
        self::assertTrue($service->recordingConnection->isTransactionActive(), 'a read inside the caller\'s transaction ends only its own level');
        self::assertSame(1, $service->recordingConnection->getTransactionNestingLevel());
    }

    public function testAnApplicationsBracketAroundThePurgesReadsClearsTheLimitsOnlyAtItsOwnEnd(): void
    {
        $service = new AIConversationEvalPurgeServiceOnARecordingConnection();

        $service->beginStatementLimitedRead();
        try {
            $service->findIdsWhereColumnIn('SomeTable', 'aiConversationId', [10], AIConversationEvalPurgeSettings::newInstance());
            self::assertNotContains('SET SESSION max_statement_time = DEFAULT', $service->recordingConnection->recordedCalls, 'the inner end keeps the outer read\'s limit');
        } finally {
            $service->endStatementLimitedRead();
        }

        self::assertSame(['commit', 'commit', 'SET SESSION innodb_lock_wait_timeout = DEFAULT', 'SET SESSION max_statement_time = DEFAULT'], array_slice($service->recordingConnection->recordedCalls, -4));
        self::assertFalse($service->recordingConnection->isTransactionActive());
    }

    /**
     * Asserts that the recorded calls are a sequence of brackets `begin, SET limit, <read>, commit, clear, clear` and
     * returns the reads in order.
     *
     * @param string[] $recordedCalls
     * @return string[]
     */
    protected static function assertEveryReadIsBracketed(array $recordedCalls): array
    {
        $bracketLength = count(self::MARIADB_CALLS_BEFORE_A_READ) + 1 + count(self::MARIADB_CALLS_AFTER_A_READ);
        self::assertSame(0, count($recordedCalls) % $bracketLength, implode("\n", $recordedCalls));
        $readStatements = [];
        foreach (array_chunk($recordedCalls, $bracketLength) as $bracketCalls) {
            self::assertSame(self::MARIADB_CALLS_BEFORE_A_READ, array_slice($bracketCalls, 0, 2));
            self::assertSame(self::MARIADB_CALLS_AFTER_A_READ, array_slice($bracketCalls, 3));
            self::assertStringStartsWith('SELECT ', $bracketCalls[2]);
            $readStatements[] = $bracketCalls[2];
        }
        return $readStatements;
    }
}
