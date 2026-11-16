<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals\ConversationPurge;

use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeKeys;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeSettings;
use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeStep;
use DDD\Domain\Base\Repo\DB\Dialect\DialectResolver;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The statements of one purge step on both engines. The MariaDB statements are the ones the purge has always written
 * (a limited `DELETE` / `UPDATE`); PostgreSQL has no limit on a write and picks the row ids by a limited read of the
 * same table instead. Every name is quoted by the dialect.
 */
class AIConversationEvalPurgeStepTest extends UnitTestCase
{
    public function testOnMariaDbTheWriteIsALimitedStatementScopedToTheIdList(): void
    {
        $mariaDbDialect = DialectResolver::forDriver('pdo_mysql');
        $deleteStep = AIConversationEvalPurgeStep::deleteRows('EntityAIConversationTurnEvents', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION);
        $nullifyStep = AIConversationEvalPurgeStep::nullifyColumn('EntityAccountMemories', 'sourceMessageId', AIConversationEvalPurgeKeys::KIND_MESSAGE);

        self::assertSame('DELETE FROM `EntityAIConversationTurnEvents` WHERE `aiConversationId` IN (:ids) LIMIT 500', $deleteStep->buildWriteSql(500, $mariaDbDialect));
        self::assertSame('UPDATE `EntityAccountMemories` SET `sourceMessageId` = NULL WHERE `sourceMessageId` IN (:ids) LIMIT 500', $nullifyStep->buildWriteSql(500, $mariaDbDialect));
        self::assertSame('SELECT COUNT(*) FROM `EntityAIConversationTurnEvents` WHERE `aiConversationId` IN (:ids)', $deleteStep->buildCountSql($mariaDbDialect));
        self::assertStringEndsWith('LIMIT 1', $deleteStep->buildWriteSql(0, $mariaDbDialect), 'a statement writes at least one row');
    }

    public function testOnPostgresTheWritePicksItsRowIdsByALimitedReadOfTheSameTable(): void
    {
        $postgresDialect = DialectResolver::forDriver('pdo_pgsql');
        $deleteStep = AIConversationEvalPurgeStep::deleteRows('EntityAIConversationTurnEvents', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION);
        $nullifyStep = AIConversationEvalPurgeStep::nullifyColumn('EntityAccountMemories', 'sourceMessageId', AIConversationEvalPurgeKeys::KIND_MESSAGE);

        self::assertSame(
            'DELETE FROM "EntityAIConversationTurnEvents" WHERE "id" IN (SELECT "id" FROM (SELECT "id" FROM "EntityAIConversationTurnEvents"'
            . ' WHERE "aiConversationId" IN (:ids) LIMIT 50) AS "pickedRows")',
            $deleteStep->buildWriteSql(50, $postgresDialect)
        );
        self::assertSame(
            'UPDATE "EntityAccountMemories" SET "sourceMessageId" = NULL WHERE "id" IN (SELECT "id" FROM (SELECT "id" FROM "EntityAccountMemories"'
            . ' WHERE "sourceMessageId" IN (:ids) LIMIT 500) AS "pickedRows")',
            $nullifyStep->buildWriteSql(500, $postgresDialect)
        );
        self::assertSame('SELECT COUNT(*) FROM "EntityAIConversationTurnEvents" WHERE "aiConversationId" IN (:ids)', $deleteStep->buildCountSql($postgresDialect));
    }

    public function testTheLabelNamesTheTableOfADeleteAndTheColumnOfANullify(): void
    {
        $deleteStep = AIConversationEvalPurgeStep::deleteRows('EntityTempAIConversationMessages', 'id', AIConversationEvalPurgeKeys::KIND_MESSAGE, hasHeavyRows: true);
        $nullifyStep = AIConversationEvalPurgeStep::nullifyColumn('EntityAccountMemories', 'sourceMessageId', AIConversationEvalPurgeKeys::KIND_MESSAGE);

        self::assertSame('EntityTempAIConversationMessages', $deleteStep->getReportLabel());
        self::assertTrue($deleteStep->isDelete());
        self::assertSame('EntityAccountMemories.sourceMessageId = NULL', $nullifyStep->getReportLabel());
        self::assertFalse($nullifyStep->isDelete());
        self::assertFalse($nullifyStep->hasHeavyRows, 'a nullify writes one small column');
    }

    public function testTheStepKeyTellsApartTwoStepsOnOneTable(): void
    {
        $firstDelete = AIConversationEvalPurgeStep::deleteRows('EntityTaskDependencies', 'dependentTaskId', 'task');
        $secondDelete = AIConversationEvalPurgeStep::deleteRows('EntityTaskDependencies', 'prerequisiteTaskId', 'task');

        self::assertSame($firstDelete->getReportLabel(), $secondDelete->getReportLabel(), 'the report sums both under the table');
        self::assertSame('delete:EntityTaskDependencies.dependentTaskId', $firstDelete->getStepKey());
        self::assertSame('delete:EntityTaskDependencies.prerequisiteTaskId', $secondDelete->getStepKey());
        self::assertSame(
            'nullify:EntityAccountMemories.sourceMessageId',
            AIConversationEvalPurgeStep::buildStepKey(AIConversationEvalPurgeStep::ACTION_NULLIFY, 'EntityAccountMemories', 'sourceMessageId')
        );
    }

    public function testHeavyRowsAreWrittenInTheSmallStatementSize(): void
    {
        $settings = AIConversationEvalPurgeSettings::newInstance();
        $settings->heavyRowsPerStatement = 50;
        $settings->lightRowsPerStatement = 500;

        self::assertSame(50, AIConversationEvalPurgeStep::deleteRows('EntityTempAIConversationMessages', 'id', AIConversationEvalPurgeKeys::KIND_MESSAGE, hasHeavyRows: true)->getRowLimit($settings));
        self::assertSame(500, AIConversationEvalPurgeStep::deleteRows('EntityAIConversationTurnEvents', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION)->getRowLimit($settings));
        $settings->lightRowsPerStatement = 0;
        self::assertSame(1, AIConversationEvalPurgeStep::deleteRows('EntityAIConversationTurnEvents', 'aiConversationId', AIConversationEvalPurgeKeys::KIND_CONVERSATION)->getRowLimit($settings));
    }
}
