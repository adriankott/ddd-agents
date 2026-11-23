<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalJudgeVerdict;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimRunResult;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The judge's verdict of a judged run meets the case's deterministic gates and the application's own: a PASS never overrules them. */
class AgentEvalJudgedRunServiceDeterministicGatesTest extends UnitTestCase
{
    public function testAJudgedPassWithoutTheRequiredCompletedCallIsAFail(): void
    {
        $agentEvalSimRunResult = (new AgentEvalJudgedRunServiceWithHostGateDouble())->applyGates(
            $this->runResultWithVerdict('PASS', $this->conversationWithCall('showDriveFiles', AIConversationMessage::STATUS_FAILED)),
            $this->scope(),
            AgentEvalSimCase::fromArray(['id' => 'deck', 'expect' => ['requiredTools' => ['showDriveFiles'], 'emitsWidget' => 'deck']])
        );
        self::assertSame(AgentEvalJudgeVerdict::VERDICT_FAIL, $agentEvalSimRunResult->getAgentEvalJudgeVerdict()->getVerdict());
        self::assertSame(
            ["required tool not completed: 'showDriveFiles' must have a COMPLETED call, completed were []"],
            $agentEvalSimRunResult->getDeterministicGateFailures(),
            'the final answer\'s widget is no gate of a judged run'
        );
    }

    public function testTheApplicationsGateCountsLikeTheCasesAndAMetGateKeepsThePass(): void
    {
        $judgedRunService = new AgentEvalJudgedRunServiceWithHostGateDouble();
        $metCase = AgentEvalSimCase::fromArray(['id' => 'deck', 'expect' => ['requiredTools' => ['showDriveFiles']]]);
        $passed = $judgedRunService->applyGates($this->runResultWithVerdict('PASS', $this->conversationWithCall('showDriveFiles', AIConversationMessage::STATUS_COMPLETED)), $this->scope(), $metCase);
        self::assertTrue($passed->getAgentEvalJudgeVerdict()->isPass());
        self::assertSame([], $passed->getDeterministicGateFailures());

        $judgedRunService->hostGateFailures = ['document check: no slide'];
        $failed = $judgedRunService->applyGates($this->runResultWithVerdict('PASS', $this->conversationWithCall('showDriveFiles', AIConversationMessage::STATUS_COMPLETED)), $this->scope(), $metCase);
        self::assertSame(AgentEvalJudgeVerdict::VERDICT_FAIL, $failed->getAgentEvalJudgeVerdict()->getVerdict());
        self::assertSame(['document check: no slide'], $failed->getDeterministicGateFailures());
    }

    protected function runResultWithVerdict(string $verdict, AIConversation $conversation): AgentEvalSimRunResult
    {
        return AgentEvalSimRunResult::forRun(AgentEvalJudgeVerdict::fromArray(['verdict' => $verdict, 'reasons' => 'judge']), AgentEvalSimRunResult::STOP_REASON_DONE, 2, $conversation);
    }

    protected function scope(): AgentEvalScope
    {
        $ownerAccount = new Account();
        $ownerAccount->id = 5;
        return AgentEvalScope::forAgentSpace(null, $ownerAccount);
    }

    protected function conversationWithCall(string $toolName, string $status): AIConversation
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->message = '';
        $message->indexInConversation = 0;
        /** @var ToolCall $toolCall */
        $toolCall = self::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = $toolName;
        $toolCall->arguments = [];
        $toolCall->status = $status;
        $message->toolCall = $toolCall;
        $messages = new AIConversationMessages();
        $messages->add($message);
        $conversation = AIConversation::newInstance();
        $conversation->messages = $messages;
        return $conversation;
    }
}
