<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Fixtures\ToolCallComparisons\Host\Presentation\Mcp\Notes;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Libs\RecordedRuns\RecordedRunClockAndIdSources;
use DDD\Domain\AI\Repo\DB\Conversations\TraceEvents\DBAIConversationTraceEventModel;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEventSnapshotModel;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Fixtures\ToolCallComparisons\Host\Domain\ComparisonSnapshotKeptMessage;
use DDD\Tests\Agents\Presentation\Services\Probes\ProbeNoteRequestDto;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fixture: the application's notes package of the comparison tests. It shares its short name with the module's package
 * of the fixture root `Addon`; the application's root comes first, so this class is the one a script names.
 */
class ComparisonNotesMCPPackage extends MCPPackage
{
    public static ?string $name = 'comparison.notes';

    public static ?string $description = 'Comparison notes package of the application';

    /** @var string The text the tool puts before the note; a test changes it to make a replay differ */
    public static string $resultPrefix = 'recorded: ';

    /**
     * Records a note of the application.
     *
     * @example {"note": "Order coffee"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    #[MCPToolSummary('Record one note.')]
    public function recordComparisonNote(ProbeNoteRequestDto $requestDto): string
    {
        return static::$resultPrefix . (string)$requestDto->note;
    }

    /**
     * Keeps a snapshot of the note: one row in a table without a scope column (the turn-event snapshots), one row read
     * through the conversation's id (a trace event of the calling conversation), and a message that names both rows.
     * Its token and its moments come from the pinned sources, so two runs write the same values.
     *
     * @example {"note": "Order coffee"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    #[MCPToolSummary('Keep a snapshot of one note.')]
    public function keepComparisonSnapshot(ProbeNoteRequestDto $requestDto, AgentToolCallProvenance $agentToolCallProvenance): string
    {
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $pinnedMoment = RecordedRunClockAndIdSources::readNow();
        $snapshotId = $this->insertRow($connection, DBAIConversationTurnEventSnapshotModel::getTableName(), [
            'hash' => RecordedRunClockAndIdSources::mintHexToken(16),
            'payload' => (string)json_encode(['note' => (string)$requestDto->note]),
            'created' => $pinnedMoment->format('Y-m-d H:i:s'),
            'updated' => $pinnedMoment->format('Y-m-d H:i:s'),
        ]);
        $traceEventId = $this->insertRow($connection, DBAIConversationTraceEventModel::getTableName(), [
            'aiConversationId' => (int)$agentToolCallProvenance->conversationId,
            'occurredAtUnixMs' => $pinnedMoment->getTimestamp() * 1000,
            'source' => 'comparison',
            'eventType' => 'snapshot',
            'eventKey' => 'snapshot',
            'created' => $pinnedMoment->format('Y-m-d H:i:s'),
            'updated' => $pinnedMoment->format('Y-m-d H:i:s'),
        ]);
        /** @var MessageBusInterface $messageBus */
        $messageBus = DDDService::instance()->getService('messenger.default_bus');
        $messageBus->dispatch(new ComparisonSnapshotKeptMessage($snapshotId, $traceEventId));
        return "snapshot #{$snapshotId}, trace event #{$traceEventId}";
    }

    /**
     * One INSERT with every identifier quoted by the connection's platform; the new row's id.
     *
     * @param array<string, int|string> $valuesByColumn
     */
    protected function insertRow(Connection $connection, string $tableName, array $valuesByColumn): int
    {
        $quotedColumns = [];
        foreach (array_keys($valuesByColumn) as $columnName) {
            $quotedColumns[] = $connection->quoteIdentifier($columnName);
        }
        $connection->executeStatement(
            'INSERT INTO ' . $connection->quoteIdentifier($tableName) . ' (' . implode(', ', $quotedColumns) . ') VALUES ('
                . implode(', ', array_fill(0, count($valuesByColumn), '?')) . ')',
            array_values($valuesByColumn)
        );
        return (int)$connection->lastInsertId();
    }
}
