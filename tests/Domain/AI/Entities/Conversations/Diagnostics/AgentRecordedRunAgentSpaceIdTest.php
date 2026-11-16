<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Recorded-run comparison: a conversation row's `agentSpaceId` is a declared id key of its own family. Before, it went
 * through the free-number path, which reads only numbers of at least six digits as ids, so a five-digit space the run
 * created (69490 recorded, 69494 replayed) stayed raw and the two runs differed.
 */
class AgentRecordedRunAgentSpaceIdTest extends UnitTestCase
{
    protected const string AGENT_SPACES_TABLE = 'AgentSpaces';

    protected function idNormalizationOfRun(int $agentSpacesHighWaterMark): AgentRecordedRunIdNormalization
    {
        return AgentRecordedRunIdNormalization::withHighWaterMarks(
            [self::AGENT_SPACES_TABLE => $agentSpacesHighWaterMark],
            [AgentRecordedRunIdNormalization::FAMILY_AGENT_SPACE => self::AGENT_SPACES_TABLE]
        );
    }

    public function testAgentSpaceIdIsADeclaredIdKeyOfTheAgentSpaceFamily(): void
    {
        self::assertSame(
            AgentRecordedRunIdNormalization::FAMILY_AGENT_SPACE,
            AgentRecordedRunIdNormalization::TABLE_FAMILY_BY_ID_KEY['agentSpaceId']
        );
    }

    public function testASpaceTheHarnessNamedReadsAsItsScopePlaceholderInBothRuns(): void
    {
        $recordedIdNormalization = $this->idNormalizationOfRun(69400);
        $recordedIdNormalization->addScopeIds(['agentSpace' => 69490]);
        $replayedIdNormalization = $this->idNormalizationOfRun(69400);
        $replayedIdNormalization->addScopeIds(['agentSpace' => 69494]);

        $recordedRow = $recordedIdNormalization->normalizeStructure(['id' => 'x', 'agentSpaceId' => 69490]);
        $replayedRow = $replayedIdNormalization->normalizeStructure(['id' => 'x', 'agentSpaceId' => 69494]);

        self::assertSame('<agentSpace>', $recordedRow['agentSpaceId']);
        self::assertSame($recordedRow, $replayedRow);
    }

    public function testASpaceTheRunCreatedWithoutNamingItReadsAsItsCreationOrdinalInBothRuns(): void
    {
        $recordedIdNormalization = $this->idNormalizationOfRun(69489);
        $recordedIdNormalization->addMintedIds([self::AGENT_SPACES_TABLE => [69490]]);
        $replayedIdNormalization = $this->idNormalizationOfRun(69493);
        $replayedIdNormalization->addMintedIds([self::AGENT_SPACES_TABLE => [69494]]);

        $recordedRow = $recordedIdNormalization->normalizeStructure(['agentSpaceId' => 69490]);
        $replayedRow = $replayedIdNormalization->normalizeStructure(['agentSpaceId' => '69494']);

        self::assertSame('<agentSpace#1>', $recordedRow['agentSpaceId']);
        self::assertSame($recordedRow, $replayedRow);
    }

    public function testAFixedSpaceThatExistedBeforeBothRunsStaysRawAndEqual(): void
    {
        $recordedIdNormalization = $this->idNormalizationOfRun(69489);
        $recordedIdNormalization->addMintedIds([self::AGENT_SPACES_TABLE => [69490]]);
        $replayedIdNormalization = $this->idNormalizationOfRun(69493);
        $replayedIdNormalization->addMintedIds([self::AGENT_SPACES_TABLE => [69494]]);

        $recordedRow = $recordedIdNormalization->normalizeStructure(['agentSpaceId' => 4711]);
        $replayedRow = $replayedIdNormalization->normalizeStructure(['agentSpaceId' => 4711]);

        self::assertSame(4711, $recordedRow['agentSpaceId']);
        self::assertSame($recordedRow, $replayedRow);
        self::assertSame(4712, $replayedIdNormalization->normalizeStructure(['agentSpaceId' => 4712])['agentSpaceId'], 'another existing space is a difference');
    }
}
