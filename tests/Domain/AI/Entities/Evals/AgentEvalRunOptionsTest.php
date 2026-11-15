<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Evals\AgentEvalArms;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOutcome;
use DDD\Tests\Agents\Domain\AI\Services\Eval\AgentEvalProgressLinesRecorder;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * What a caller sets for one evaluation run and what the run gives back: the arms (none chosen by default), the report
 * lines handed on or dropped, the run's record of the conversation it built (taken once by the teardown) and of the
 * conversations it seeded, and the outcome's conversation, reload diagnostics, seeded ids and eviction rows.
 */
class AgentEvalRunOptionsTest extends UnitTestCase
{
    protected function buildConversation(): AIConversation
    {
        /** @var AIConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(AIConversation::class);
        return $conversation;
    }

    public function testOptionsWithoutArmsRunOnArmsWithoutAChoice(): void
    {
        $agentEvalRunOptions = AgentEvalRunOptions::forArms();

        $agentEvalArms = $agentEvalRunOptions->getAgentEvalArms();

        self::assertSame($agentEvalArms, $agentEvalRunOptions->getAgentEvalArms(), 'the same arms for every conversation of the run');
        self::assertSame([], $agentEvalArms->getRowValuesByRowName());
        self::assertFalse($agentEvalRunOptions->persistConversations);
        self::assertFalse($agentEvalRunOptions->everyWriteToolBlocked);
        self::assertNull($agentEvalRunOptions->simulatedOwnerDevice);
        self::assertNull($agentEvalRunOptions->aiModelName);
    }

    public function testTheArmsOfTheCallerAreKept(): void
    {
        $agentEvalArms = AgentEvalArms::newInstance();
        $agentEvalArms->skillPreloadRow = 'on';

        self::assertSame($agentEvalArms, AgentEvalRunOptions::forArms($agentEvalArms)->getAgentEvalArms());
    }

    public function testReportLinesGoToTheReporterOrAreDropped(): void
    {
        $progressLinesRecorder = new AgentEvalProgressLinesRecorder();
        $agentEvalRunOptions = AgentEvalRunOptions::forArms();
        $agentEvalRunOptions->reportProgress('dropped');
        $agentEvalRunOptions->progressReporter = $progressLinesRecorder;

        $agentEvalRunOptions->reportProgress('restore setup createEntry: {}');

        self::assertSame(['restore setup createEntry: {}'], $progressLinesRecorder->progressLines);
    }

    public function testTheBuiltConversationIsHandedOverOnce(): void
    {
        $agentEvalRunOptions = AgentEvalRunOptions::forArms();
        $firstConversation = $this->buildConversation();
        $lastConversation = $this->buildConversation();
        $agentEvalRunOptions->recordBuiltConversation($firstConversation);
        $agentEvalRunOptions->recordBuiltConversation($lastConversation);

        self::assertSame($lastConversation, $agentEvalRunOptions->takeBuiltConversation());
        self::assertNull($agentEvalRunOptions->takeBuiltConversation());
    }

    public function testTheSeededConversationIdsAreKeptInTheirOrder(): void
    {
        $agentEvalRunOptions = AgentEvalRunOptions::forArms();
        $agentEvalRunOptions->recordSeededConversationId(31);
        $agentEvalRunOptions->recordSeededConversationId(30);

        self::assertSame([31, 30], $agentEvalRunOptions->getSeededConversationIds());
    }

    public function testTheOutcomeCarriesTheConversationAndWhatTheRunRecorded(): void
    {
        $conversation = $this->buildConversation();
        $agentEvalRunOutcome = AgentEvalRunOutcome::forConversation($conversation, ['found' => false]);
        $agentEvalRunOutcome->recordSeededConversationIds([3 => 31, 4 => 30]);
        $agentEvalRunOutcome->recordEvictionTurns([1 => ['prompt' => 'p', 'snapshot' => [], 'newlyLoaded' => [], 'evicted' => [], 'metrics' => []]]);

        self::assertSame($conversation, $agentEvalRunOutcome->getConversation());
        self::assertSame(['found' => false], $agentEvalRunOutcome->getReloadDiagnostics());
        self::assertSame([31, 30], $agentEvalRunOutcome->getSeededConversationIds());
        self::assertSame([['prompt' => 'p', 'snapshot' => [], 'newlyLoaded' => [], 'evicted' => [], 'metrics' => []]], $agentEvalRunOutcome->getEvictionTurns());
        self::assertSame([], AgentEvalRunOutcome::forConversation($conversation)->getReloadDiagnostics());
    }
}
