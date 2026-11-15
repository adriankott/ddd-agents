<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentAppendedHistoryInsertion;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentAppendOnlyCall;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * Under append-only loading a skill the platform loaded before the turn's first model call goes on the wire the way
 * the model's own load looks: a `load_skills` call and its result in front of the appended item. A skill the model
 * loaded itself, or a preload after a model step, stays as it was.
 */
class PreloadedSkillGoesOnTheWireAsALoadCallTest extends UnitTestCase
{
    public function testASkillThePlatformLoadedBeforeTheFirstModelCallIsAppendedAsALoadCall(): void
    {
        $agentAppendOnlyCall = AgentAppendOnlyCall::buildFor($this->buildModelInput(LoadedCapability::SOURCE_DECISION_MODEL, $this->ownerRow(7)), [], [], new AISkills(), true);

        self::assertSame(['Media.Images'], $agentAppendOnlyCall->appendedPreloadLoadCallSkillNamesOfThisCall);
        $wireEntries = $agentAppendOnlyCall->appendedHistoryItems->insertionOfThisCall?->toWireEntries() ?? [];
        self::assertSame('assistant', $wireEntries[0]['role'] ?? null);
        self::assertSame('call_preload_7', $wireEntries[0]['tool_calls'][0]['id'] ?? null);
        self::assertSame(LoadSkillsTool::NAME, $wireEntries[0]['tool_calls'][0]['function']['name'] ?? null);
        self::assertSame('{"skill_names":["Media.Images"]}', $wireEntries[0]['tool_calls'][0]['function']['arguments'] ?? null);
        self::assertSame(['role' => 'tool', 'tool_call_id' => 'call_preload_7', 'content' => AIConversationMessage::LOAD_SKILLS_RESULT_LOADED], $wireEntries[1] ?? null);
        self::assertSame('developer', $wireEntries[2]['role'] ?? null, 'the skill instructions follow the load result');
    }

    public function testASkillTheModelLoadedOrAPreloadAfterAModelStepGetsNoLoadCall(): void
    {
        $byTheModel = AgentAppendOnlyCall::buildFor($this->buildModelInput(LoadedCapability::SOURCE_MODEL, $this->ownerRow(7)), [], [], new AISkills(), true);
        self::assertSame([], $byTheModel->appendedPreloadLoadCallSkillNamesOfThisCall);

        $afterAModelStep = AgentAppendOnlyCall::buildFor($this->buildModelInput(LoadedCapability::SOURCE_DECISION_MODEL, $this->ownerRow(7), $this->loadCallRow(8)), [], [], new AISkills(), true);
        self::assertSame([], $afterAModelStep->appendedPreloadLoadCallSkillNamesOfThisCall);
        self::assertSame('developer', ($afterAModelStep->appendedHistoryItems->insertionOfThisCall?->toWireEntries() ?? [])[0]['role'] ?? null);
    }

    public function testAnInsertionWithoutAPreloadKeepsItsEntries(): void
    {
        $agentAppendedHistoryInsertion = new AgentAppendedHistoryInsertion();
        $agentAppendedHistoryInsertion->developerText = '# Skills loaded now';
        self::assertSame([['role' => 'developer', 'content' => '# Skills loaded now']], $agentAppendedHistoryInsertion->toWireEntries());
        self::assertSame('call_preload_12', AgentAppendedHistoryInsertion::buildPreloadLoadCallId(12));
    }

    protected function buildModelInput(string $source, AIConversationMessage ...$messages): AgentModelInput
    {
        $agentModelInput = new AgentModelInput();
        $agentModelInput->loadedCapabilities = LoadedCapabilities::fromCanonicalEntries([
            'Media.Images' => ['name' => 'Media.Images', 'type' => LoadedCapability::TYPE_SKILL, 'source' => $source],
        ]);
        $agentModelInput->messages = $messages;
        return $agentModelInput;
    }

    protected function ownerRow(int $index): AIConversationMessage
    {
        /** @var AIConversationMessage $ownerRow */
        $ownerRow = static::instantiateWithoutConstructor(AIConversationMessage::class);
        $ownerRow->role = AIConversationMessage::ROLE_USER;
        $ownerRow->messageType = AIConversationMessage::MESSAGE_TEXT;
        $ownerRow->authorType = AIConversationMessage::AUTHOR_USER;
        $ownerRow->message = 'which image model is best?';
        $ownerRow->indexInConversation = $index;
        return $ownerRow;
    }

    protected function loadCallRow(int $index): AIConversationMessage
    {
        /** @var AIConversationMessage $loadCallRow */
        $loadCallRow = static::instantiateWithoutConstructor(AIConversationMessage::class);
        $loadCallRow->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $loadCallRow->indexInConversation = $index;
        /** @var ToolCall $toolCall */
        $toolCall = static::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = LoadSkillsTool::NAME;
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $loadCallRow->toolCall = $toolCall;
        return $loadCallRow;
    }
}
