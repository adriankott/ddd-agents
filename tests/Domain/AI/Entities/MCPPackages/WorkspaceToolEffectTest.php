<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentPausedTurnRecord;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunCounts;
use DDD\Domain\AI\Entities\Evals\AgentEvalScratchFiles;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolAnnotations;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A WORKSPACE call changes only the agent's own workspace: it is neither a read nor a write of the turn — no
 * identical-write refusal, no change of the record, its own bucket in the paused-turn record and its own count, MCP
 * hints that say it writes but destroys nothing; an evaluation gives each case run its own workspace key and files.
 */
class WorkspaceToolEffectTest extends UnitTestCase
{
    protected function buildWorkspaceCallRow(int $index): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->indexInConversation = $index;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_AGENT;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->message = '';
        $toolCall = new ToolCall();
        $toolCall->name = 'runShellCommand';
        $toolCall->arguments = ['command' => 'python make_report.py'];
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $toolCall->result = 'Wrote report.xlsx (3 sheets).';
        $toolCall->stampEffect(new MCPToolEffect(MCPToolEffect::WORKSPACE));
        $message->toolCall = $toolCall;
        return $message;
    }

    public function testAWorkspaceCallIsNeitherAReadNorAWriteOfTheTurn(): void
    {
        $toolCall = $this->buildWorkspaceCallRow(1)->toolCall;
        self::assertTrue($toolCall->changesOnlyTheWorkspace());
        self::assertFalse($toolCall->isReadOnly());
        self::assertFalse($toolCall->changesState());
        self::assertFalse($toolCall->refusesIdenticalRepeat(), 'a repeated shell command is new work');
        self::assertFalse($toolCall->isReusableRead());
        self::assertFalse($toolCall->canHaveChangedTheRecordWhenCompleted(false), 'the workspace is no part of the record');
        self::assertTrue($toolCall->canHaveChangedTheRecordWhenCompleted(true), 'a widget row it wrote still counts');
    }

    public function testThePausedTurnRecordAndTheRunCountsListItOnItsOwn(): void
    {
        $record = AgentPausedTurnRecord::composeFromTurnRows([$this->buildWorkspaceCallRow(3)]);
        self::assertSame([], $record->saved);
        self::assertCount(1, $record->workspaceChanges);
        self::assertStringContainsString('runShellCommand @3 WORKSPACE CHANGE', $record->toSignalText());

        $agentEvalRunCounts = new AgentEvalRunCounts();
        $agentEvalRunCounts->addMessage($this->buildWorkspaceCallRow(3));
        self::assertSame(1, $agentEvalRunCounts->workspaceChanges);
        self::assertSame(0, $agentEvalRunCounts->outwardWrites);
    }

    public function testTheMcpHintsOfAWorkspaceTool(): void
    {
        $mcpToolAnnotations = MCPToolAnnotations::fromToolDeclarations(new MCPToolEffect(MCPToolEffect::WORKSPACE), false);
        self::assertFalse($mcpToolAnnotations->readOnlyHint);
        self::assertFalse($mcpToolAnnotations->destructiveHint);
        self::assertFalse($mcpToolAnnotations->idempotentHint);
        self::assertFalse($mcpToolAnnotations->openWorldHint);
    }

    public function testAnEvaluationGivesEachCaseRunItsOwnWorkspaceAndFiles(): void
    {
        $agentEvalRunnerService = new AgentEvalRunnerService();
        self::assertSame('eval-workspace-shell-csv-summary-r2-a1b2c3d4', $agentEvalRunnerService->buildWorkspaceKeyOfCase('Workspace.Shell/csv summary', 2, 'a1b2c3d4'));
        self::assertSame('eval-workspace-shell-csv-summary-a1b2c3d4', $agentEvalRunnerService->buildWorkspaceKeyOfCase('Workspace.Shell/csv summary', null, 'a1b2c3d4'), 'no repetition counted: no r segment');
        self::assertNotSame(
            $agentEvalRunnerService->buildWorkspaceKeyOfCase('Workspace.Shell/csv summary', 1, 'a1b2c3d4'),
            $agentEvalRunnerService->buildWorkspaceKeyOfCase('Workspace.Shell/csv summary', 1, 'e5f6a7b8')
        );

        $agentEvalScratchFiles = AgentEvalScratchFiles::fromFixtureEntries([
            ['path' => '/data/orders.csv', 'content' => "id,total\n1,9.5\n"],
            ['path' => 'logo.png', 'contentBase64' => base64_encode("\x89PNG")],
            ['content' => 'no path'],
        ]);
        self::assertSame(2, $agentEvalScratchFiles->count());
        self::assertSame('data/orders.csv', $agentEvalScratchFiles->first()?->path);
        self::assertSame("\x89PNG", $agentEvalScratchFiles->getElements()[1]->content);
    }
}
