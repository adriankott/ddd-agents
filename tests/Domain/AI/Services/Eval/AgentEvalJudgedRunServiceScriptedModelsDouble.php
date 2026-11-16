<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlock;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimCase;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Exceptions\Evals\AgentEvalHarnessModelCallFailedException;
use DDD\Domain\AI\Services\Eval\AgentEvalJudgedRunService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use RuntimeException;

/**
 * The judged-run service with its model calls answered from scripts and the agent's turn replaced: the driver's and
 * the judge's answers come from {@see $scriptedDriverReplies} and {@see $scriptedJudgeAnswers} (an exhausted script
 * answers empty; a call whose failure reason is set throws the failed harness call with it, like a refused load), every
 * model call is recorded with its model, usage scope and prompt, and a driven turn appends one
 * assistant text (`Answer <n>`) without a model call, its request snapshot sent at {@see DRIVEN_CALL_SENT_AT}. The
 * application's override points answer what the test sets. Three steps that write a stored row or reset the process
 * are recorded instead: the run identity of a stored conversation, the fresh process state of a later turn and the
 * purpose (its name and the text of its anchor message). Everything else — the conversation, the owner messages, the
 * turn loop, the reload, the prompts, the trace and the transcript — is the service's own.
 */
class AgentEvalJudgedRunServiceScriptedModelsDouble extends AgentEvalJudgedRunService
{
    /** @var int When the model call of every driven turn was sent */
    public const int DRIVEN_CALL_SENT_AT = 1_800_000_000;

    /** @var array<int, string[]> The run-identity properties written per stored conversation, in order */
    public array $runIdentityWrites = [];

    /** @var int How often the process was put on a fresh worker job's state */
    public int $processStateResets = 0;

    /** @var array<int, array{purposeName: string, anchorMessageText: string|null}> The purposes applied, in order */
    public array $appliedPurposes = [];

    /** @var string[] The driver's answers, in order */
    public array $scriptedDriverReplies = [];

    /** @var string[] The judge's answers, in order */
    public array $scriptedJudgeAnswers = [];

    /** @var string|null The provider's refusal of every judge call when set (the call throws the failed harness call) */
    public ?string $judgeCallFailureReason = null;

    /** @var int How many judge calls fail with {@see $judgeCallFailureReason}; the later ones answer from the script */
    public int $remainingJudgeCallFailures = PHP_INT_MAX;

    /** @var string|null The provider's refusal of every driver call when set (the call throws the failed harness call) */
    public ?string $driverCallFailureReason = null;

    /** @var array<int, array{aiModelName: string, aiUsageScope: string, promptText: string}> Every model call, in order */
    public array $modelCalls = [];

    /** @var array<int, string[]> The owner texts of the conversation at each driven turn */
    public array $ownerTextsPerDrive = [];

    /** @var array<int, string[]> The tools blocked in this process at each driven turn */
    public array $blockedToolNamesPerDrive = [];

    /** @var int The turn after which the driven conversation is closed; 0 = never */
    public int $closeConversationAfterTurn = 0;

    /** @var string|null Thrown by a driven turn when set */
    public ?string $agentRunErrorMessage = null;

    /** @var int How often the database connection was healed */
    public int $connectionHeals = 0;

    /** @var string|null What {@see extractSystemPrompt()} answers */
    public ?string $systemPromptForTrace = null;

    /** @var int How often the case's fixtures were applied */
    public int $caseFixtureApplications = 0;

    /** @var string|null What {@see buildHostEndStateTextForJudge()} answers */
    public ?string $hostEndStateText = null;

    /** @var string|null The widget type whose blocks {@see buildTextOfHostWidgetShownToOwner()} renders as text */
    public ?string $hostWidgetType = null;

    /** @var string|null What {@see applyHostSectionsToVerdictPrompt()} sets as `HOST_RULES`; null = nothing */
    public ?string $hostRules = null;

    /** @var string|null What {@see applyHostSectionsToVerdictPrompt()} sets as `HOST_SECTIONS`; null = nothing */
    public ?string $hostSections = null;

    /** @var string|null The signal source {@see isHostOwnerReplyRow()} declares an owner reply */
    public ?string $hostOwnerReplySignalSource = null;

    public function callModel(AgentEvalScope $agentEvalScope, string $aiModelName, string $promptText, string $aiUsageScope): string
    {
        $this->modelCalls[] = ['aiModelName' => $aiModelName, 'aiUsageScope' => $aiUsageScope, 'promptText' => $promptText];
        if ($aiUsageScope === AgentAIUsageScope::AI_AGENT_JUDGE_EVALUATION) {
            if ($this->judgeCallFailureReason !== null && $this->remainingJudgeCallFailures > 0) {
                $this->remainingJudgeCallFailures--;
                throw new AgentEvalHarnessModelCallFailedException($aiModelName, $aiUsageScope, $this->judgeCallFailureReason);
            }
            return (string)(array_shift($this->scriptedJudgeAnswers) ?? '');
        }
        if ($this->driverCallFailureReason !== null) {
            throw new AgentEvalHarnessModelCallFailedException($aiModelName, $aiUsageScope, $this->driverCallFailureReason);
        }
        return (string)(array_shift($this->scriptedDriverReplies) ?? '');
    }

    /**
     * The usage scopes of the model calls, in order.
     *
     * @return string[]
     */
    public function getUsageScopesOfModelCalls(): array
    {
        return array_column($this->modelCalls, 'aiUsageScope');
    }

    protected function driveAgent(AIConversation $conversation, AgentEvalScope $agentEvalScope, AgentEvalRunOptions $agentEvalRunOptions, bool $curateMemoryInline = false): void
    {
        $ownerTexts = [];
        foreach ($conversation->messages->getElements() as $message) {
            if (($message->role ?? null) === AIConversationMessage::ROLE_USER) {
                $ownerTexts[] = (string)$message->message;
            }
        }
        $this->ownerTextsPerDrive[] = $ownerTexts;
        $this->blockedToolNamesPerDrive[] = MCPToolExecutorService::$blockedToolNames;
        if ($this->agentRunErrorMessage !== null) {
            throw new RuntimeException($this->agentRunErrorMessage);
        }
        $assistantMessage = $conversation->appendMessage(
            AIConversationMessage::newInstance(),
            AIConversationMessage::MESSAGE_TEXT,
            'Answer ' . count($this->ownerTextsPerDrive),
            role: AIConversationMessage::ROLE_ASSISTANT,
            authorType: AIConversationMessage::AUTHOR_ASSISTANT
        );
        $requestSnapshot = new RequestSnapshot();
        $requestSnapshot->sentAt = DateTime::fromTimestamp(static::DRIVEN_CALL_SENT_AT);
        $assistantMessage->requestSnapshot = $requestSnapshot;
        if ($this->closeConversationAfterTurn === count($this->ownerTextsPerDrive)) {
            $conversation->runState = AIConversationRunState::COMPLETED;
        }
    }

    protected function persistRunIdentityIfPersisted(AIConversation $conversation): void
    {
        if (isset($conversation->id)) {
            $this->runIdentityWrites[] = array_values(array_filter(
                ['purposeName', 'reportChannel', 'initiatorType', 'runSettings'],
                static fn (string $propertyName): bool => isset($conversation->{$propertyName})
            ));
        }
    }

    protected function resetProcessStateLikeANewWorkerJob(): void
    {
        $this->processStateResets++;
    }

    protected function applyEvalPurpose(AIConversation $conversation, string $purposeName, ?AIConversationMessage $anchorMessage, AgentEvalRunOptions $agentEvalRunOptions): void
    {
        $this->appliedPurposes[] = ['purposeName' => $purposeName, 'anchorMessageText' => $anchorMessage?->message];
    }

    protected function healIdleDatabaseConnections(): void
    {
        $this->connectionHeals++;
    }

    protected function extractSystemPrompt(AIConversation $conversation): ?string
    {
        return $this->systemPromptForTrace;
    }

    protected function applyCaseFixtures(AIConversation $conversation, AgentEvalSimCase $agentEvalSimCase, AgentEvalScope $agentEvalScope): void
    {
        $this->caseFixtureApplications++;
    }

    protected function buildHostEndStateTextForJudge(AIConversation $conversation): ?string
    {
        return $this->hostEndStateText;
    }

    protected function buildTextOfHostWidgetShownToOwner(AIConversation $conversation, WidgetBlock $widgetBlock): ?string
    {
        if ($this->hostWidgetType === null || !$widgetBlock->isOfType($this->hostWidgetType)) {
            return null;
        }
        return '[widget ' . $widgetBlock->ordinal . ' as the owner sees it]';
    }

    protected function applyHostSectionsToVerdictPrompt(AIPrompt $verdictPrompt, AIConversation $conversation, AgentEvalSimCase $agentEvalSimCase): void
    {
        if ($this->hostRules !== null) {
            $verdictPrompt->setParameter('HOST_RULES', $this->hostRules);
        }
        if ($this->hostSections !== null) {
            $verdictPrompt->setParameter('HOST_SECTIONS', $this->hostSections);
        }
    }

    protected function isHostOwnerReplyRow(AIConversationMessage $message): bool
    {
        return $this->hostOwnerReplySignalSource !== null && ($message->signalSource ?? null) === $this->hostOwnerReplySignalSource;
    }
}
