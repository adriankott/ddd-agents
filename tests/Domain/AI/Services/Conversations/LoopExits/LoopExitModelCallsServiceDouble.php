<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\LoopExits;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\ModelResponse;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCalls;
use DDD\Domain\AI\Exceptions\AiBudgetExceededException;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService;

/**
 * The scripted model of the loop-exit stage: it answers a queue of responses (text, tool calls, cost, provider
 * error), or the same tool call every step, or a different tool call every step, or throws the budget exception at
 * one call. The base system prompt is empty and no tool schema is offered: the loop's exits are under test, not the
 * offer.
 */
class LoopExitModelCallsServiceDouble extends AIConversationModelCallsService
{
    /** @var array<int, array<string, mixed>> text, toolCalls [[name, arguments]], costInUSD, providerError */
    public array $modelResponses = [];

    /** @var array{0: string, 1: array<string, mixed>}|null the same tool call at every step */
    public ?array $sameToolCallEveryStep = null;

    /** A different tool call at every step: never an answer, never an identical repeat. */
    public bool $differentToolCallEveryStep = false;

    /** The 0-based model call that throws the budget exception (null: none). */
    public ?int $budgetExceededAtModelCall = null;

    public int $modelCallCount = 0;

    public function __construct()
    {
        // The parent service is container-managed and holds no constructor state.
    }

    public function performModelCallOfStep(
        AIConversation $conversation,
        array $toolSchemas,
        string|array|null $forceToolChoice = null,
        ?string $modelNameOverride = null,
        string $baseSystemPrompt = '',
        ?AgentRun $agentRun = null
    ): ModelResponse {
        $modelCallIndex = $this->modelCallCount++;
        if ($this->budgetExceededAtModelCall === $modelCallIndex) {
            throw new AiBudgetExceededException('The monthly AI budget is used up.');
        }
        if ($this->differentToolCallEveryStep) {
            return self::buildModelResponse(['toolCalls' => [['probeTool' . $modelCallIndex, ['n' => $modelCallIndex]]]]);
        }
        if ($this->sameToolCallEveryStep !== null) {
            return self::buildModelResponse(['toolCalls' => [$this->sameToolCallEveryStep]]);
        }
        return self::buildModelResponse(array_shift($this->modelResponses) ?? ['text' => '']);
    }

    public function buildOfferedToolSchemas(AIConversation $conversation, bool $allowThreads = true, ?AIConversationMessage $anchorMessage = null, ?AgentRun $agentRun = null): array
    {
        return [];
    }

    public function resolveBaseSystemPrompt(AIConversation $conversation): string
    {
        return '';
    }

    /** @param array<string, mixed> $scriptedResponse */
    protected static function buildModelResponse(array $scriptedResponse): ModelResponse
    {
        $toolCalls = null;
        foreach ($scriptedResponse['toolCalls'] ?? [] as $callNumber => $scriptedToolCall) {
            $toolCalls ??= new ToolCalls();
            $toolCall = new ToolCall();
            $toolCall->toolCallId = 'call-' . $callNumber . '-' . $scriptedToolCall[0];
            $toolCall->name = $scriptedToolCall[0];
            $toolCall->arguments = $scriptedToolCall[1];
            $toolCalls->add($toolCall);
        }
        $modelResponse = new ModelResponse($scriptedResponse['text'] ?? null, $toolCalls);
        $modelResponse->modelName = 'scripted-model';
        $modelResponse->costInUSD = $scriptedResponse['costInUSD'] ?? null;
        $modelResponse->providerError = $scriptedResponse['providerError'] ?? null;
        return $modelResponse;
    }
}
