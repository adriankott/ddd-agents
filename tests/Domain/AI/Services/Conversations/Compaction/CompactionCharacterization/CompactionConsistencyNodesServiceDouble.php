<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Compaction\CompactionCharacterization;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Compaction\AIConversationCompactionNodes;
use DDD\Domain\AI\Services\Conversations\ConversationCompactionNodesService;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The three node reads of the compaction consistency report (the full set, the cover read, the frontier read),
 * answered from the node sets the test gives instead of the database. Each call is recorded with the conversation id
 * it was given. A read the test gives no answer for is derived in memory from the full set — the cover from
 * {@see AIConversationCompactionNodes::buildCover()}, the frontier from
 * {@see AIConversationCompactionNodes::buildExposedNodesByLevel()} — i.e. a database whose bounded reads agree with the
 * full scan; a test gives a different answer to model a read that disagrees.
 */
class CompactionConsistencyNodesServiceDouble extends ConversationCompactionNodesService
{
    use CompactionCharacterizationFixtures;

    /** The full set `findAllNodesForConversation()` answers (null = the read answers null). */
    public ?AIConversationCompactionNodes $allNodes = null;

    /** The cover the cover read answers; null = the cover of {@see $allNodes}. */
    public ?AIConversationCompactionNodes $coverNodes = null;

    /** The frontier the frontier read answers; null = the exposed nodes of {@see $allNodes}. */
    public ?AIConversationCompactionNodes $exposedNodes = null;

    /** @var array<int, array{method: string, aiConversationId: int}> every call, in order */
    public array $calls = [];

    /** @return string[] the names of the methods called, in order */
    public function calledMethodNames(): array
    {
        return array_map(static fn (array $call): string => $call['method'], $this->calls);
    }

    public function findAllNodesForConversation(AIConversation $aIConversation): ?AIConversationCompactionNodes
    {
        $this->calls[] = ['method' => 'findAllNodesForConversation', 'aiConversationId' => (int)($aIConversation->id ?? 0)];
        return $this->allNodes;
    }

    public function findCoverNodesForConversation(AIConversation $aIConversation, ?DateTime $asOf = null): AIConversationCompactionNodes
    {
        $this->calls[] = ['method' => 'findCoverNodesForConversation', 'aiConversationId' => (int)($aIConversation->id ?? 0)];
        if ($this->coverNodes !== null) {
            return $this->coverNodes;
        }
        // elements set directly: `add()` would re-parent every node onto the answer set
        return self::fixtureNodeSet($this->allNodes?->buildCover()->getCoverNodes() ?? []);
    }

    public function findExposedNodesForConversation(AIConversation $aIConversation): AIConversationCompactionNodes
    {
        $this->calls[] = ['method' => 'findExposedNodesForConversation', 'aiConversationId' => (int)($aIConversation->id ?? 0)];
        if ($this->exposedNodes !== null) {
            return $this->exposedNodes;
        }
        $exposedNodes = [];
        foreach ($this->allNodes?->buildExposedNodesByLevel()->toNodesByLevel() ?? [] as $exposedNodesAtLevel) {
            foreach ($exposedNodesAtLevel as $exposedNode) {
                $exposedNodes[] = $exposedNode;
            }
        }
        return self::fixtureNodeSet($exposedNodes);
    }
}
