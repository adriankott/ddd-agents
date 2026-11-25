<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction\CompactionCharacterization;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaTools;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionToolTelemetry;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCoverToolTelemetry;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCallEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Services\Conversations\SupervisorCheckpointService;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the compaction telemetry counts and what the summary input calls an action:
 * {@see AIConversationCompactionToolTelemetry::isCountedCompletion()} and {@see AIConversationCompactionToolTelemetry::isCountedWrite()}
 * over status, refused duplicate, meta tool and effect (the stored stamp first, the catalog only without one), what
 * {@see AIConversationCompactionToolTelemetry::fromMessages()} makes of them, `AgentModelInput::buildSummaryActionLine()`
 * per meta tool and per work call (its list of skipped meta tools differs from the telemetry's), and the supervisor's
 * read of the loaded cover ({@see SupervisorCheckpointService}'s `buildCoverToolTelemetryBefore()`). The membership list
 * itself is {@see \DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Tools\AgentMetaToolsCompactionWorkCountsTest}'s,
 * the cover scenarios {@see \DDD\Tests\Agents\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionCoverToolTelemetryDifferentialTest}'s.
 * Container-free.
 */
class CompactionTelemetryCountedCallsAndSummaryActionLineTest extends UnitTestCase
{
    use CompactionCharacterizationFixtures;

    /** A tool-call row; `$stampedEffect` null = a row stored without a stamp, ToolCallEffect::NONE = stamped "no effect". */
    protected static function toolCallRow(
        int $index,
        string $toolName,
        ?string $status = AIConversationMessage::STATUS_COMPLETED,
        ?string $stampedEffect = null,
        bool $wasRefusedAsDuplicate = false,
        bool $resultIsReusedWithinTurn = true
    ): AIConversationMessage {
        $message = self::fixtureToolCallMessage($index, $toolName, ['call' => $index], 'done', $status);
        $message->toolCall->wasRefusedAsDuplicate = $wasRefusedAsDuplicate;
        if ($stampedEffect !== null) {
            $message->toolCall->effect = ToolCallEffect::fromMcpToolEffect(
                $stampedEffect === ToolCallEffect::NONE ? null : new MCPToolEffect($stampedEffect, resultIsReusedWithinTurn: $resultIsReusedWithinTurn)
            );
        }
        return $message;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The counted predicates
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Columns: tool name, status, stamped effect (null = no stamp), refused as duplicate → counted completion, counted write.
     *
     * @return array<string, array{0: string, 1: string|null, 2: string|null, 3: bool, 4: bool, 5: bool}>
     */
    public static function countedCallCases(): array
    {
        $completed = AIConversationMessage::STATUS_COMPLETED;
        return [
            'a work call stamped WRITE' => ['saveNote', $completed, MCPToolEffect::WRITE, false, true, true],
            'a work call stamped DELETE' => ['removeNote', $completed, MCPToolEffect::DELETE, false, true, true],
            'a work call stamped READ is work, no write' => ['readNotes', $completed, MCPToolEffect::READ, false, true, false],
            'a work call stamped WORKSPACE is work, no write' => ['writeScratchFile', $completed, MCPToolEffect::WORKSPACE, false, true, false],
            'a work call stamped NONE is work, no write' => ['pingService', $completed, ToolCallEffect::NONE, false, true, false],
            // the two meta tools outside the list: the owner report is a counted write, a plan read counted work
            'the owner report (catalog WRITE, no stamp)' => ['send_owner_report', $completed, null, false, true, true],
            'a task plan read (catalog READ, no stamp)' => ['get_task_plans', $completed, null, false, true, false],
            'askOwner is not counted' => ['askOwner', $completed, null, false, false, false],
            // a meta tool on the list is not counted even where the catalog calls it a write
            'update_task_plan (catalog WRITE) is not counted' => ['update_task_plan', $completed, null, false, false, false],
            // SUCCESS = COMPLETED, never merely "not FAILED"
            'a failed write' => ['saveNote', AIConversationMessage::STATUS_FAILED, MCPToolEffect::WRITE, false, false, false],
            'a pending write' => ['saveNote', AIConversationMessage::STATUS_PENDING, MCPToolEffect::WRITE, false, false, false],
            'a write without a status' => ['saveNote', null, MCPToolEffect::WRITE, false, false, false],
            'a cancelled write' => ['saveNote', AIConversationMessage::STATUS_CANCELLED, MCPToolEffect::WRITE, false, false, false],
            'a write refused as a duplicate' => ['saveNote', $completed, MCPToolEffect::WRITE, true, false, false],
            // the write question reads the STORED effect first, the catalog only for a row without a stamp
            'an unknown name without a stamp counts, but is no write' => ['aToolThatNoLongerExists', $completed, null, false, true, false],
            'the stamp wins for a name the catalog no longer knows' => ['aToolThatNoLongerExists', $completed, MCPToolEffect::WRITE, false, true, true],
            'a NONE stamp wins over the catalog\'s WRITE' => ['send_owner_report', $completed, ToolCallEffect::NONE, false, true, false],
            'an empty name is not counted' => ['', $completed, MCPToolEffect::WRITE, false, false, false],
        ];
    }

    #[DataProvider('countedCallCases')]
    public function testTheCountedPredicates(
        string $toolName,
        ?string $status,
        ?string $stampedEffect,
        bool $wasRefusedAsDuplicate,
        bool $expectedCountedCompletion,
        bool $expectedCountedWrite
    ): void {
        $toolCall = self::toolCallRow(1, $toolName, $status, $stampedEffect, $wasRefusedAsDuplicate)->toolCall;

        self::assertSame($expectedCountedCompletion, AIConversationCompactionToolTelemetry::isCountedCompletion($toolCall), 'counted completion');
        self::assertSame($expectedCountedWrite, AIConversationCompactionToolTelemetry::isCountedWrite($toolCall), 'counted write');
    }

    public function testTheTelemetryOfARangeCountsTheCountedCallsAndExcerptsTheCountedWrites(): void
    {
        $telemetry = AIConversationCompactionToolTelemetry::fromMessages([
            self::fixtureAssistantMessage(0),
            self::toolCallRow(1, 'saveNote', stampedEffect: MCPToolEffect::WRITE),
            self::toolCallRow(2, 'readNotes', stampedEffect: MCPToolEffect::READ),
            self::toolCallRow(3, 'saveNote', AIConversationMessage::STATUS_FAILED, MCPToolEffect::WRITE),
            self::toolCallRow(4, 'saveNote', stampedEffect: MCPToolEffect::WRITE, wasRefusedAsDuplicate: true),
            self::toolCallRow(5, 'askOwner'),
            self::toolCallRow(6, 'send_owner_report'),
            self::toolCallRow(7, 'get_task_plans'),
            self::toolCallRow(8, 'load_skills'),
            self::toolCallRow(9, 'aToolThatNoLongerExists'),
            self::toolCallRow(10, 'saveNote', stampedEffect: MCPToolEffect::WRITE),
        ]);

        self::assertSame(
            ['saveNote' => 2, 'readNotes' => 1, 'send_owner_report' => 1, 'get_task_plans' => 1, 'aToolThatNoLongerExists' => 1],
            $telemetry->getCompletedCountsByToolName()
        );
        self::assertSame(
            ['[1] saveNote {"call":1} => done', '[6] send_owner_report {"call":6} => done', '[10] saveNote {"call":10} => done'],
            $telemetry->persistedWrites
        );
        self::assertFalse($telemetry->writesOmitted);
        self::assertFalse($telemetry->countsIncomplete);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The ACTION line of the summary input — AgentModelInput::buildSummaryActionLine()
    // ---------------------------------------------------------------------------------------------------------------

    protected static function summaryActionLine(AIConversationMessage $message): ?string
    {
        $agentModelInput = self::fixtureInstantiate(AgentModelInput::class);
        return self::fixtureInvoke($agentModelInput, 'buildSummaryActionLine', [$message]);
    }

    /**
     * The summary input skips load_skills, load_tool, check_delegations, delegate, the plan tools,
     * wait_for_sub_agents, message_sub_agent and findWidgets, but NOT askOwner (excluded from the telemetry counts),
     * and renders get_task_plans (a read never reused) and send_owner_report as actions.
     */
    public function testTheSummaryActionLineOfEachMetaTool(): void
    {
        $actionLineByToolName = [];
        foreach (AgentMetaTools::TOOL_CLASSES as $metaToolClass) {
            $actionLineByToolName[$metaToolClass::NAME] = self::summaryActionLine(self::fixtureToolCallMessage(1, $metaToolClass::NAME, ['k' => 1]));
        }

        self::assertSame([
            'load_skills' => null,
            'load_tool' => null,
            'check_delegations' => null,
            'get_task_plans' => 'ACTION: get_task_plans({"k":1}) — completed',
            'update_task_plan' => null,
            'send_owner_report' => 'ACTION: send_owner_report({"k":1}) — completed',
            'delegate' => null,
            'set_task_status' => null,
            'askOwner' => 'ACTION: askOwner({"k":1}) — completed',
            'wait_for_sub_agents' => null,
            'message_sub_agent' => null,
            'findWidgets' => null,
        ], $actionLineByToolName);
    }

    /** @return array<string, array{0: AIConversationMessage, 1: string|null}> */
    public static function workCallActionLineCases(): array
    {
        $textRow = self::fixtureAssistantMessage(4);
        $toolCallRowWithoutCall = self::fixtureToolCallMessage(5, 'saveNote');
        $toolCallRowWithoutCall->toolCall = null;
        $textRowWithACall = self::toolCallRow(6, 'saveNote', stampedEffect: MCPToolEffect::WRITE);
        $textRowWithACall->messageType = AIConversationMessage::MESSAGE_TEXT;
        return [
            'a write' => [self::toolCallRow(1, 'saveNote', stampedEffect: MCPToolEffect::WRITE), 'ACTION: saveNote({"call":1}) — completed'],
            'a failed write' => [self::toolCallRow(1, 'saveNote', AIConversationMessage::STATUS_FAILED, MCPToolEffect::WRITE), 'ACTION: saveNote({"call":1}) — failed'],
            'a pending write is no action' => [self::toolCallRow(1, 'saveNote', AIConversationMessage::STATUS_PENDING, MCPToolEffect::WRITE), null],
            'a running write is no action' => [self::toolCallRow(1, 'saveNote', AIConversationMessage::STATUS_RUNNING, MCPToolEffect::WRITE), null],
            'a write with a cancel request is no action' => [self::toolCallRow(1, 'saveNote', AIConversationMessage::STATUS_CANCEL_REQUESTED, MCPToolEffect::WRITE), null],
            'a cancelled write is no action' => [self::toolCallRow(1, 'saveNote', AIConversationMessage::STATUS_CANCELLED, MCPToolEffect::WRITE), null],
            'a write without a status is no action' => [self::toolCallRow(1, 'saveNote', null, MCPToolEffect::WRITE), null],
            'a read reused within the turn is no action' => [self::toolCallRow(1, 'readNotes', stampedEffect: MCPToolEffect::READ), null],
            'a read that is never reused is an action' => [self::toolCallRow(1, 'readNotes', stampedEffect: MCPToolEffect::READ, resultIsReusedWithinTurn: false), 'ACTION: readNotes({"call":1}) — completed'],
            'an unknown name without a stamp is an action' => [self::toolCallRow(1, 'aToolThatNoLongerExists'), 'ACTION: aToolThatNoLongerExists({"call":1}) — completed'],
            'a text row is no action' => [$textRow, null],
            'a tool-call row without its call is no action' => [$toolCallRowWithoutCall, null],
            'a text row that carries a call is no action' => [$textRowWithACall, null],
            'a call without a name is no action' => [self::toolCallRow(1, '', stampedEffect: MCPToolEffect::WRITE), null],
        ];
    }

    #[DataProvider('workCallActionLineCases')]
    public function testTheSummaryActionLineOfAWorkCall(AIConversationMessage $message, ?string $expectedActionLine): void
    {
        self::assertSame($expectedActionLine, self::summaryActionLine($message));
    }

    /** @return array<string, array{0: AIConversationMessage[], 1: int}> rows → the index a summary generated now covers */
    public static function summaryCoverageCases(): array
    {
        $write = MCPToolEffect::WRITE;
        $running = AIConversationMessage::STATUS_RUNNING;
        $ownerRow = self::fixtureAssistantMessage(2);
        $ownerRow->role = AIConversationMessage::ROLE_USER;
        $text = self::fixtureAssistantMessage(4);
        return [
            'finished calls only: the newest row' => [[$ownerRow, self::toolCallRow(3, 'saveNote', stampedEffect: $write), $text], 4],
            'a running write holds the marker before it' => [[$ownerRow, self::toolCallRow(3, 'saveNote', $running, $write), $text], 2],
            'the oldest of two unfinished writes counts' => [[$ownerRow, self::toolCallRow(3, 'saveNote', AIConversationMessage::STATUS_PENDING, $write), self::toolCallRow(4, 'saveNote', $running, $write)], 2],
            'a cancelled write is over' => [[$ownerRow, self::toolCallRow(3, 'saveNote', AIConversationMessage::STATUS_CANCELLED, $write), $text], 4],
            'a running read and a running delegation are never an action' => [[$ownerRow, self::toolCallRow(3, 'readNotes', $running, MCPToolEffect::READ), self::toolCallRow(4, 'delegate', $running)], 4],
            'a call left behind by an earlier turn holds nothing back' => [[self::toolCallRow(1, 'saveNote', $running, $write), $ownerRow, $text], 4],
        ];
    }

    /** @param AIConversationMessage[] $messages */
    #[DataProvider('summaryCoverageCases')]
    public function testTheSummaryMarkerStaysBeforeAnUnfinishedActionOfTheNewestTurn(array $messages, int $expectedCoverageIndex): void
    {
        $conversation = self::fixtureConversation();
        $conversation->messages = self::fixtureMessageSet($messages);
        $conversation->compactionNodes = self::fixtureNodeSet([]);

        self::assertSame($expectedCoverageIndex, $conversation->getDisplaySummaryCoverageIndex());
    }

    public function testTheArgumentsOfAnActionAreCutAtFourHundredCharacters(): void
    {
        $message = self::fixtureToolCallMessage(1, 'saveNote', ['text' => str_repeat('é', 500)]);
        $message->toolCall->effect = ToolCallEffect::fromMcpToolEffect(new MCPToolEffect(MCPToolEffect::WRITE));

        // the JSON keeps the characters unescaped: `{"text":"` and 391 of the 500 characters
        self::assertSame('ACTION: saveNote({"text":"' . str_repeat('é', 391) . ') — completed', self::summaryActionLine($message));
    }

    // ---------------------------------------------------------------------------------------------------------------
    // The supervisor's read of the loaded cover — SupervisorCheckpointService::buildCoverToolTelemetryBefore()
    // ---------------------------------------------------------------------------------------------------------------

    protected static function supervisorCoverToolTelemetry(AIConversation $conversation, int $firstLoadedMessageIndex, int $upToMessageIndex): AIConversationCoverToolTelemetry
    {
        $coverToolTelemetry = self::fixtureInvoke(
            self::fixtureInstantiate(SupervisorCheckpointService::class),
            'buildCoverToolTelemetryBefore',
            [$conversation, $firstLoadedMessageIndex, $upToMessageIndex]
        );
        self::assertInstanceOf(AIConversationCoverToolTelemetry::class, $coverToolTelemetry);
        return $coverToolTelemetry;
    }

    public function testAnUnloadedTreeIsNotLoaded(): void
    {
        $conversation = self::fixtureConversation(); // compactionNodes uninitialized: isset() is false

        $coverToolTelemetry = self::supervisorCoverToolTelemetry($conversation, PHP_INT_MAX, 99);

        self::assertSame([], $coverToolTelemetry->getCompletedCountsByToolName());
        self::assertFalse($coverToolTelemetry->countsIncomplete);
        self::assertFalse(isset($conversation->compactionNodes), 'the lazy tree stayed unloaded');
    }

    public function testTheSupervisorReadsTheLiveCoverOfTheLoadedTreeOnce(): void
    {
        $conversation = self::fixtureConversation();
        $conversation->compactionNodes = self::fixtureNodeSet([
            self::fixtureNodeWithTelemetry(0, 9, AIConversationCompactionToolTelemetry::fromMessages([self::toolCallRow(1, 'saveNote', stampedEffect: MCPToolEffect::WRITE)])),
            self::fixtureNodeWithTelemetry(10, 19, AIConversationCompactionToolTelemetry::fromMessages([self::toolCallRow(11, 'readNotes', stampedEffect: MCPToolEffect::READ)])),
            self::fixtureNodeWithTelemetry(20, 29, AIConversationCompactionToolTelemetry::fromMessages([self::toolCallRow(21, 'saveNote', stampedEffect: MCPToolEffect::WRITE)])),
        ], CompactionCoverCountingNodesDouble::class);

        // the rows from 20 are loaded: the node [20-29] is counted from the rows, not from the cover
        $coverToolTelemetry = self::supervisorCoverToolTelemetry($conversation, 20, 99);

        self::assertSame(['saveNote' => 1, 'readNotes' => 1], $coverToolTelemetry->getCompletedCountsByToolName());
        self::assertFalse($coverToolTelemetry->countsIncomplete);
        $nodeSet = $conversation->compactionNodes;
        self::assertInstanceOf(CompactionCoverCountingNodesDouble::class, $nodeSet);
        self::assertSame(['now'], $nodeSet->buildCoverCalls, 'one cover, as of now');
    }
}
