<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryCurationModelCall;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryCurationResult;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\Models\Selection\ModelScope;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusOpenAIReasoningEffort;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DateTimeZone;

/**
 * The memory curation's first step: the candidate memories in the owner's new messages of a conversation. The model
 * sees the new messages (the delta) and, read-only, the messages before them to resolve references; it never sees the
 * store. Every candidate is an ADD proposal; the reconciliation decides.
 */
#[ArgusLoad(
    loadEndpoint: 'POST:https://openrouter.ai/api/v1/chat/completions',
    cacheLevel: ArgusCache::CACHELEVEL_NONE,
    cacheTtl: ArgusCache::CACHELEVEL_NONE
)]
#[ArgusLanguageModel(
    defaultAIPromptName: AgentAIPromptNames::APP_AI_MEMORY_EXTRACT,
    temperature: 0.1,
    responseFormat: ArgusLanguageModel::RESPONSE_FORMAT_JSON_OBJECT,
    aiUsageScope: AgentAIUsageScope::AI_MEMORY_EXTRACT,
    modelScope: ModelScope::COMPACTION,
    providerPrioritizationStrategy: ArgusLanguageModel::PROVIDER_PRIORITIZATION_STRATEGY_SPEED,
    reasoningEffort: ArgusOpenAIReasoningEffort::LOW,
)]
class ArgusAgentMemoryExtraction extends ArgusAgentMemoryCurationCall
{
    use ArgusTrait, ArgusAILanguageModelTrait;

    /** @var int The characters each block (earlier context, new messages) shows at most */
    public const int MAX_CHARS_PER_BLOCK = 2000;

    /** @var string The STRUCTURED_HOMES text when the application names none */
    public const string NO_STRUCTURED_HOMES = '(the application names none: every datum a tool of the application edits is such a home)';

    /** @var AIConversationMessages|null The new messages the candidates come from */
    public ?AIConversationMessages $conversationDelta = null;

    /** @var AIConversationMessages|null The messages before them, read only to resolve references */
    public ?AIConversationMessages $recentContextWindow = null;

    /** @var DateTimeZone|null The zone the send time of each line is shown in; null = no time shown */
    public ?DateTimeZone $sourceTimezone = null;

    /** @var string The application's data with a home of its own (one line each), never copied into a memory */
    public string $structuredHomes = self::NO_STRUCTURED_HOMES;

    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        if ($this->billingEntity) {
            $this->billAICostsTo(entityWithinAccount: $this->billingEntity);
        } elseif ($this->budgetAccount) {
            $this->billAICostsTo(account: $this->budgetAccount);
        }
        $prompt = $this->getAIPrompt();
        $prompt->setParameter('MEMORY_LANGUAGE', $this->memoryLanguageCode);
        $prompt->setParameter('STRUCTURED_HOMES', $this->structuredHomes);
        return $prompt;
    }

    public function getUserContent(): string|array
    {
        $userContent = '';
        // the earlier context keeps its tail: the end of the turn before is what a terse reply refers to
        $contextLines = $this->renderConversationLines($this->recentContextWindow, keepTailOnTruncate: true);
        if ($contextLines !== '') {
            $userContent .= "EARLIER CONTEXT (read-only — use ONLY to resolve references in the NEW messages; do NOT"
                . " extract memories that are solely about these earlier turns):\n" . $contextLines . "\n";
        }
        $userContent .= "NEW MESSAGES (extract candidate memories from THESE):\n" . $this->renderConversationLines($this->conversationDelta);
        return $userContent;
    }

    /**
     * The messages as lines `<role> [#<id> <moment>]: <text>` within {@see MAX_CHARS_PER_BLOCK}; system rows are left
     * out. `$keepTailOnTruncate` keeps the newest messages (the earlier context), otherwise the oldest (the delta).
     *
     * @param AIConversationMessages|null $messages
     * @param bool $keepTailOnTruncate
     * @return string
     */
    public function renderConversationLines(?AIConversationMessages $messages, bool $keepTailOnTruncate = false): string
    {
        if (!$messages) {
            return '';
        }
        $renderableMessages = [];
        foreach ($messages->getElements() as $message) {
            if ($message->role !== AIConversationMessage::ROLE_SYSTEM) {
                $renderableMessages[] = $message;
            }
        }
        if ($renderableMessages === []) {
            return '';
        }
        $remainingChars = static::MAX_CHARS_PER_BLOCK;
        if ($keepTailOnTruncate) {
            $linesNewestFirst = [];
            for ($messageIndex = count($renderableMessages) - 1; $messageIndex >= 0; $messageIndex--) {
                $messageText = (string)$renderableMessages[$messageIndex]->message;
                if (mb_strlen($messageText) > $remainingChars) {
                    if ($remainingChars > 0) {
                        $linesNewestFirst[] = $this->renderLine($renderableMessages[$messageIndex], '…' . mb_substr($messageText, -$remainingChars));
                    }
                    break;
                }
                $remainingChars -= mb_strlen($messageText);
                $linesNewestFirst[] = $this->renderLine($renderableMessages[$messageIndex], $messageText);
            }
            return implode('', array_reverse($linesNewestFirst));
        }
        $lines = '';
        foreach ($renderableMessages as $message) {
            $messageText = (string)$message->message;
            if (mb_strlen($messageText) > $remainingChars) {
                if ($remainingChars > 0) {
                    $lines .= $this->renderLine($message, mb_substr($messageText, 0, $remainingChars) . '…');
                }
                break;
            }
            $remainingChars -= mb_strlen($messageText);
            $lines .= $this->renderLine($message, $messageText);
        }
        return $lines;
    }

    /**
     * One line: the role, the message id (what an operation's `sourceMessageId` names) and, with a zone, the send time.
     *
     * @param AIConversationMessage $message
     * @param string $text
     * @return string
     */
    protected function renderLine(AIConversationMessage $message, string $text): string
    {
        $createdTime = $message->getCreatedTime();
        $stamp = ($createdTime !== null && $this->sourceTimezone !== null) ? ' ' . $createdTime->formatForModel($this->sourceTimezone) : '';
        return strtolower((string)$message->role) . ' [#' . ($message->id ?? '?') . $stamp . ']: ' . $text . "\n";
    }

    protected function applyLoadResult(string $resultText): void
    {
        $this->result = AgentMemoryCurationResult::fromModelJson($resultText);
    }

    public function getCurationStage(): ?string
    {
        return AgentMemoryCurationModelCall::STAGE_EXTRACTION;
    }
}
