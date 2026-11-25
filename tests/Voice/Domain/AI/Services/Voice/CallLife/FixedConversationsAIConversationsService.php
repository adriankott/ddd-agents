<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Entities\Entity;
use Throwable;

/**
 * The conversation service of the package over conversations the test hands in: `find()` answers from
 * {@see $conversationsById} and records each id; the environment filter keeps {@see $conversationIdsOfThisEnvironment};
 * the automation gate answers {@see $refusesAutomation}; the working-state slice write records its keys and throws
 * {@see $workingStateSliceFailure} when one is set.
 */
class FixedConversationsAIConversationsService extends AIConversationsService
{
    /** @var array<int, AIConversation> */
    public array $conversationsById = [];

    /** @var int[] The ids find() was asked for, in order */
    public array $foundConversationIds = [];

    /** @var int[] */
    public array $conversationIdsOfThisEnvironment = [];

    /** @var array<int, int[]> The id lists the environment filter was asked about, in order */
    public array $environmentQuestions = [];

    public bool $refusesAutomation = false;

    /** @var array<int, array{conversationId: int, operation: string}> */
    public array $automationQuestions = [];

    /** @var array<int, array{conversationId: int, sliceKeys: string[]}> */
    public array $persistedWorkingStateSlices = [];

    public ?Throwable $workingStateSliceFailure = null;

    public function persistAgentWorkingStateSlice(AIConversation $conversation, string ...$sliceKeys): void
    {
        if ($this->workingStateSliceFailure !== null) {
            throw $this->workingStateSliceFailure;
        }
        $this->persistedWorkingStateSlices[] = ['conversationId' => (int)$conversation->id, 'sliceKeys' => $sliceKeys];
    }

    public function find(int|string|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        $this->foundConversationIds[] = (int)$entityId;

        return $this->conversationsById[(int)$entityId] ?? null;
    }

    public function keepConversationIdsOfCurrentEnvironment(array $conversationIds): array
    {
        $this->environmentQuestions[] = $conversationIds;

        return array_values(array_intersect($conversationIds, $this->conversationIdsOfThisEnvironment));
    }

    public function refusesAutomationForConversation(AIConversation $conversation, string $operation): bool
    {
        $this->automationQuestions[] = ['conversationId' => (int)$conversation->id, 'operation' => $operation];

        return $this->refusesAutomation;
    }
}
