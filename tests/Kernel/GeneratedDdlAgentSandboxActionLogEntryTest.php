<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Kernel;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxActionLogEntry;
use DDD\Domain\Common\Services\EntityModelGeneratorService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The generated DDL of the sandbox audit: the two long texts (reason, outcome detail) carry no index, the lookups by
 * workspace and by time keep theirs. Generated in memory, no database is asked.
 */
class GeneratedDdlAgentSandboxActionLogEntryTest extends UnitTestCase
{
    public function testTheLongTextsCarryNoIndex(): void
    {
        $generatedSql = EntityModelGeneratorService::getDatabaseModels([AgentSandboxActionLogEntry::class])->first()->getSql();
        foreach (['reason', 'outcomeDetail'] as $columnName) {
            self::assertMatchesRegularExpression('/`' . $columnName . '` VARCHAR\(1000\)/i', $generatedSql, $columnName);
            self::assertDoesNotMatchRegularExpression('/(KEY|INDEX)[^\n]*\(`' . $columnName . '`\)/i', $generatedSql, $columnName);
        }
        self::assertMatchesRegularExpression('/\(`workspaceKey`,\s*`agentSpaceId`\)/', $generatedSql);
        self::assertMatchesRegularExpression('/(KEY|INDEX)[^\n]*\(`created`\)/i', $generatedSql);
    }
}
