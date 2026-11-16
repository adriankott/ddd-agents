<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One harness conversation of a tool-call comparison script: the harness creates it in the run's agent space before
 * the first case, once per run, and every call that names it runs in it — its id is stable for the whole recording,
 * and the rows the tools append to it are compared. A conversation states the provenance a case needs: the purpose
 * (none = an open chat), the owner's rows, a delegation child of another fixture (`childOf`), a scheduled run
 * (`scheduleId`) and the working state (task plan, variants) in its stored shape. Pure.
 *
 * @phpstan-consistent-constructor
 */
class ToolCallComparisonConversationFixture extends ValueObject
{
    /** The name calls refer to. */
    public string $name = '';

    /** The conversation purpose; null = an open chat. */
    public ?string $purposeName = null;

    /** The fixture this conversation is a delegation child of (its root); null = a root conversation. */
    public ?string $childOf = null;

    /** The schedule the conversation is a run of; null = no scheduled run. */
    public ?int $scheduleId = null;

    /** @var string[] the owner's text rows, appended in order before the first case */
    public array $ownerRows = [];

    /** @var array|null The working state as the script states it: the stored shape of {@see AgentWorkingState} */
    protected ?array $storedAgentWorkingState = null;

    /**
     * @param array $fixtureFields the decoded object of the script for this conversation
     */
    public static function fromScriptFields(string $name, array $fixtureFields): static
    {
        $toolCallComparisonConversationFixture = static::newInstance();
        $toolCallComparisonConversationFixture->name = $name;
        $toolCallComparisonConversationFixture->purposeName = isset($fixtureFields['purposeName']) ? (string)$fixtureFields['purposeName'] : null;
        $toolCallComparisonConversationFixture->childOf = isset($fixtureFields['childOf']) ? (string)$fixtureFields['childOf'] : null;
        $toolCallComparisonConversationFixture->scheduleId = isset($fixtureFields['scheduleId']) ? (int)$fixtureFields['scheduleId'] : null;
        $toolCallComparisonConversationFixture->ownerRows = array_values(array_map('strval', (array)($fixtureFields['ownerRows'] ?? [])));
        $toolCallComparisonConversationFixture->storedAgentWorkingState = is_array($fixtureFields['agentWorkingState'] ?? null) ? $fixtureFields['agentWorkingState'] : null;
        return $toolCallComparisonConversationFixture;
    }

    /** The key {@see ToolCallComparisonConversationFixtures} holds the fixture `$name` under. */
    public static function buildUniqueKeyOfName(string $name): string
    {
        return self::class . '_' . $name;
    }

    public function uniqueKey(): string
    {
        return static::buildUniqueKeyOfName($this->name);
    }

    /** Whether the script states a working state for the conversation. */
    public function hasAgentWorkingState(): bool
    {
        return $this->storedAgentWorkingState !== null;
    }

    /** The working state the script states, mapped from its stored shape; null when it states none. */
    public function buildAgentWorkingState(): ?AgentWorkingState
    {
        if ($this->storedAgentWorkingState === null) {
            return null;
        }
        $agentWorkingState = AgentWorkingState::newInstance();
        $agentWorkingState->mapFromRepository($this->storedAgentWorkingState);
        return $agentWorkingState;
    }
}
