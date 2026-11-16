<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeKeys;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeReport;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSettings;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeStep;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessageModel;
use DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService;
use DDD\Tests\Agents\Support\UnitTestCase;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;

/**
 * The statements of the evaluation purge on both engines, and the transaction of one write chunk. Every name is
 * quoted by the dialect, the cutoffs and id lists are bound parameters, and the engine-specific parts (the JSON
 * marker, the row statistics, the write limit, the lock and statement limits) come from the dialect. Whether the
 * statements return the right rows is the database test's ({@see \DDD\Tests\Agents\Integration\Evals\AIConversationEvalPurgeServiceDatabaseTest}).
 */
class AIConversationEvalPurgeServiceStatementsTest extends UnitTestCase
{
    protected static function buildService(string $databaseDriver): AIConversationEvalPurgeServiceRecordingDouble
    {
        $service = new AIConversationEvalPurgeServiceRecordingDouble();
        $service->databaseDriver = $databaseDriver;
        return $service;
    }

    protected static function buildNarrowSettings(): AIConversationEvalPurgeSettings
    {
        $settings = AIConversationEvalPurgeSettings::newInstance();
        $settings->evalOnly = true;
        $settings->keptAgentSpaceIds = [3];
        $settings->onlyRootConversationId = 5;
        return $settings;
    }

    public function testTheCandidateRootsOnMariaDb(): void
    {
        $conversationTableName = DBAIConversationModel::getTableName();
        self::assertSame(
            "SELECT `c`.`id` FROM `{$conversationTableName}` `c` LEFT JOIN `{$conversationTableName}` `r` ON `r`.`id` = `c`.`rootConversationId`"
            . ' WHERE `c`.`accountId` IN (:accountIds) AND `c`.`id` > :afterId AND `c`.`supervisedConversationId` IS NULL'
            . ' AND (`c`.`rootConversationId` IS NULL OR `r`.`id` IS NULL) AND `c`.`created` < :activityCutoff AND `c`.`id` = :onlyRootConversationId'
            . " AND (CASE WHEN (IF(JSON_TYPE(JSON_EXTRACT(`c`.`runSettings`, '$.\"evalRun\"')) = 'NULL', NULL, JSON_UNQUOTE(JSON_EXTRACT(`c`.`runSettings`, '$.\"evalRun\"'))) = 'true') THEN 'evalRun'"
            . " WHEN (IF(JSON_TYPE(JSON_EXTRACT(`c`.`runSettings`, '$.\"evalSeededHistory\"')) = 'NULL', NULL, JSON_UNQUOTE(JSON_EXTRACT(`c`.`runSettings`, '$.\"evalSeededHistory\"'))) = 'true') THEN 'evalSeededHistory'"
            . ' ELSE NULL END) IS NOT NULL AND (`c`.`agentSpaceId` IS NULL OR `c`.`agentSpaceId` NOT IN (:keptAgentSpaceIds)) ORDER BY `c`.`id` LIMIT 25',
            self::buildService('pdo_mysql')->buildCandidateRootConversationsSql(self::buildNarrowSettings())
        );
    }

    public function testTheCandidateRootsOnPostgres(): void
    {
        $conversationTableName = DBAIConversationModel::getTableName();
        self::assertSame(
            "SELECT \"c\".\"id\" FROM \"{$conversationTableName}\" \"c\" LEFT JOIN \"{$conversationTableName}\" \"r\" ON \"r\".\"id\" = \"c\".\"rootConversationId\""
            . ' WHERE "c"."accountId" IN (:accountIds) AND "c"."id" > :afterId AND "c"."supervisedConversationId" IS NULL'
            . ' AND ("c"."rootConversationId" IS NULL OR "r"."id" IS NULL) AND "c"."created" < :activityCutoff AND "c"."id" = :onlyRootConversationId'
            . " AND (CASE WHEN ((\"c\".\"runSettings\" #>> ARRAY['evalRun']::text[]) = 'true') THEN 'evalRun'"
            . " WHEN ((\"c\".\"runSettings\" #>> ARRAY['evalSeededHistory']::text[]) = 'true') THEN 'evalSeededHistory'"
            . ' ELSE NULL END) IS NOT NULL AND ("c"."agentSpaceId" IS NULL OR "c"."agentSpaceId" NOT IN (:keptAgentSpaceIds)) ORDER BY "c"."id" LIMIT 25',
            self::buildService('pdo_pgsql')->buildCandidateRootConversationsSql(self::buildNarrowSettings())
        );
    }

    public function testTheTreeReadsAreQuotedOnBothEngines(): void
    {
        $conversationTableName = DBAIConversationModel::getTableName();
        $messageTableName = DBAIConversationMessageModel::getTableName();
        $mariaDbService = self::buildService('pdo_mysql');
        $postgresService = self::buildService('pdo_pgsql');

        self::assertSame("SELECT `id` FROM `{$conversationTableName}` WHERE `rootConversationId` IN (:frontierIds) OR `supervisedConversationId` IN (:frontierIds)", $mariaDbService->buildTreeExpansionSql());
        self::assertSame("SELECT \"id\" FROM \"{$conversationTableName}\" WHERE \"rootConversationId\" IN (:frontierIds) OR \"supervisedConversationId\" IN (:frontierIds)", $postgresService->buildTreeExpansionSql());
        self::assertSame(
            'SELECT "id", "accountId", "runState", ("runToken" IS NOT NULL) AS "hasRunToken", "created", "updated", "lastInboundMessageAt", "runHeartbeatAt", "finalizedAt", "agentTurnOwedAt", "reportOwedAt"'
            . " FROM \"{$conversationTableName}\" WHERE \"id\" IN (:conversationIds)",
            $postgresService->buildTreeConversationsSql()
        );
        self::assertSame("SELECT MAX(`created`) FROM `{$messageTableName}` WHERE `aiConversationId` IN (:conversationIds)", $mariaDbService->buildNewestMessageSql());
        self::assertSame('SELECT "id" FROM "SomeTable" WHERE "someColumn" IN (:columnValues)', $postgresService->buildIdsWhereColumnInSql('SomeTable', 'someColumn'));
        self::assertStringEndsWith("AS \"evalMarker\" FROM \"{$conversationTableName}\" \"c\" WHERE \"c\".\"id\" IN (:rootConversationIds)", $postgresService->buildEvalMarkersOfRootConversationsSql());
    }

    public function testTheRowStatisticsComeFromEachEnginesCatalog(): void
    {
        self::assertSame(
            'SELECT `TABLE_NAME` AS `tableName`, `AVG_ROW_LENGTH` AS `averageRowBytes` FROM `information_schema`.`TABLES`'
            . ' WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` IN (:tableNames)',
            self::buildService('pdo_mysql')->buildAverageRowBytesSql()
        );
        self::assertSame(
            'SELECT "relation".relname AS "tableName",'
            . ' CASE WHEN "relation".reltuples > 0 THEN CAST(pg_relation_size("relation".oid) / "relation".reltuples AS bigint) ELSE 0 END AS "averageRowBytes"'
            . ' FROM pg_catalog.pg_class "relation" JOIN pg_catalog.pg_namespace "relationNamespace" ON "relationNamespace".oid = "relation".relnamespace'
            . " WHERE \"relationNamespace\".nspname = current_schema() AND \"relation\".relkind IN ('r', 'p') AND \"relation\".relname IN (:tableNames)",
            self::buildService('pdo_pgsql')->buildAverageRowBytesSql()
        );
    }

    public function testAWriteChunkOnMariaDbRunsInItsOwnTransactionWithTheLimitsAndClearsThemAfterTheCommit(): void
    {
        $service = new AIConversationEvalPurgeServiceOnARecordingConnection();
        $service->recordingConnection->writtenRows = 7;
        $step = AIConversationEvalPurgeStep::deleteRows('SomeTable', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION);

        self::assertSame(7, $service->executeOneStepChunk($step, [1, 2], 500));
        self::assertSame(
            [
                'begin',
                AIConversationEvalPurgeServiceReadLimitsTest::MARIADB_SESSION_LIMITS_READ,
                'SET SESSION innodb_lock_wait_timeout = ' . AIConversationEvalPurgeService::LOCK_WAIT_TIMEOUT_SECONDS,
                'SET SESSION max_statement_time = ' . AIConversationEvalPurgeService::STATEMENT_TIMEOUT_SECONDS,
                'DELETE FROM `SomeTable` WHERE `aiConversationId` IN (:ids) LIMIT 500',
                'commit',
                AIConversationEvalPurgeServiceReadLimitsTest::MARIADB_SESSION_LIMITS_RESTORE,
            ],
            $service->recordingConnection->recordedCalls
        );
    }

    public function testAWriteChunkOnPostgresSetsTheLimitsForItsTransactionOnly(): void
    {
        $service = new AIConversationEvalPurgeServiceOnARecordingConnection();
        $service->databaseDriver = 'pdo_pgsql';
        $step = AIConversationEvalPurgeStep::nullifyColumn('SomeTable', 'sourceMessageId', AIConversationEvalPurgeKeys::KIND_MESSAGE);

        $service->executeOneStepChunk($step, [1], 50);

        self::assertSame(
            [
                'begin',
                'SET LOCAL lock_timeout = 2000',
                'SET LOCAL statement_timeout = 20000',
                'UPDATE "SomeTable" SET "sourceMessageId" = NULL WHERE "id" IN (SELECT "id" FROM (SELECT "id" FROM "SomeTable" WHERE "sourceMessageId" IN (:ids) LIMIT 50) AS "pickedRows")',
                'commit',
            ],
            $service->recordingConnection->recordedCalls
        );
    }

    public function testALockWaitRollsTheChunkBackAndCountsAsASkippedChunk(): void
    {
        $service = new AIConversationEvalPurgeServiceOnARecordingConnection();
        $service->databaseDriver = 'pdo_pgsql';
        $service->recordingConnection->writeError = $this->createMock(LockWaitTimeoutException::class);
        $settings = AIConversationEvalPurgeSettings::newInstance();
        $settings->dryRun = false;
        $report = AIConversationEvalPurgeReport::forRun(false);
        $step = AIConversationEvalPurgeStep::deleteRows('SomeTable', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION);

        $stepResult = $service->runStepInChunks($step, [1, 2], $settings, $report, $service->currentSeconds(), 0);

        self::assertSame(AIConversationEvalPurgeService::STEP_RESULT_LOCK_SKIPPED, $stepResult);
        self::assertSame(1, $report->chunksSkippedOnLock);
        self::assertSame('rollBack', $service->recordingConnection->recordedCalls[4]);
        self::assertNotContains('commit', $service->recordingConnection->recordedCalls);
        self::assertFalse($service->recordingConnection->isTransactionActive(), 'no transaction is left open');
    }

    public function testAnyOtherWriteErrorRollsTheChunkBackAndReachesTheCaller(): void
    {
        $service = new AIConversationEvalPurgeServiceOnARecordingConnection();
        $service->recordingConnection->writeError = new \RuntimeException('statement over its limit');
        $step = AIConversationEvalPurgeStep::deleteRows('SomeTable', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION);

        try {
            $service->executeOneStepChunk($step, [1], 500);
            self::fail('the error reaches the caller');
        } catch (\RuntimeException $writeError) {
            self::assertSame('statement over its limit', $writeError->getMessage());
        }
        self::assertSame(['rollBack', AIConversationEvalPurgeServiceReadLimitsTest::MARIADB_SESSION_LIMITS_RESTORE], array_slice($service->recordingConnection->recordedCalls, 5));
    }
}
