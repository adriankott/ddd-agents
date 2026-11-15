<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * An older model step that only repeated `load_skills` is not replayed to the model — the model repeated its own
 * freshest pattern instead of calling the loaded tool. The step leaves the wire only when every call of it loaded
 * nothing; the NEWEST such step stays (its answer is what the next step must read), and a step that named a name that is
 * no skill stays too. The next step's tail says once that the skills are loaded — only the ones that are.
 */
class RepeatedSkillLoadStepsLeaveTheWireTest extends UnitTestCase
{
    public function testAnOlderStepOfOnlyRepeatedSkillLoadsLeavesTheWireTheNewestAndAMixedStepStay(): void
    {
        $ownerMessage = $this->textRow(AIConversationMessage::ROLE_USER, 'show me my reviews', 0);
        $firstLoad = $this->callRow(LoadSkillsTool::NAME, ['skill_names' => ['Reviews']], 100, false);
        $olderRepeatedLoad = $this->callRow(LoadSkillsTool::NAME, ['skill_names' => ['Reviews']], 120, true);
        $mixedStepLoad = $this->callRow(LoadSkillsTool::NAME, ['skill_names' => ['Reviews']], 130, true);
        $mixedStepRead = $this->callRow('getReviews', [], 0, false);
        $newestRepeatedLoad = $this->callRow(LoadSkillsTool::NAME, ['skill_names' => ['Reviews']], 140, true);

        $wireRows = ArgusAIAgentConversation::withoutStepsThatOnlyRepeatedSkillLoads([$ownerMessage, $firstLoad, $olderRepeatedLoad, $mixedStepLoad, $mixedStepRead, $newestRepeatedLoad]);

        self::assertSame([$ownerMessage, $firstLoad, $mixedStepLoad, $mixedStepRead, $newestRepeatedLoad], $wireRows);
    }

    public function testTheOnlyStepOfRepeatedSkillLoadsStays(): void
    {
        $ownerMessage = $this->textRow(AIConversationMessage::ROLE_USER, 'show me my reviews', 0);
        $repeatedLoad = $this->callRow(LoadSkillsTool::NAME, ['skill_names' => ['Reviews']], 120, true);

        self::assertSame([$ownerMessage, $repeatedLoad], ArgusAIAgentConversation::withoutStepsThatOnlyRepeatedSkillLoads([$ownerMessage, $repeatedLoad]));
    }

    public function testAProseHeadOfADroppedStepStays(): void
    {
        $proseHead = $this->textRow(AIConversationMessage::ROLE_ASSISTANT, 'Loading the reviews skill.', 90);
        $olderRepeatedLoad = $this->callRow(LoadSkillsTool::NAME, ['skill_names' => ['Reviews']], 0, true);
        $newestRepeatedLoad = $this->callRow(LoadSkillsTool::NAME, ['skill_names' => ['Reviews']], 110, true);

        self::assertSame([$proseHead, $newestRepeatedLoad], ArgusAIAgentConversation::withoutStepsThatOnlyRepeatedSkillLoads([$proseHead, $olderRepeatedLoad, $newestRepeatedLoad]));
    }

    public function testAStepThatNamedANameThatIsNoSkillStays(): void
    {
        $unknownNameLoad = $this->callRow(LoadSkillsTool::NAME, ['skill_names' => ['Posts.Seri.es.Propose']], 100, true, true);
        $olderRepeatedLoad = $this->callRow(LoadSkillsTool::NAME, ['skill_names' => ['Reviews']], 110, true);
        $newestRepeatedLoad = $this->callRow(LoadSkillsTool::NAME, ['skill_names' => ['Reviews']], 120, true);

        self::assertSame(
            [$unknownNameLoad, $newestRepeatedLoad],
            ArgusAIAgentConversation::withoutStepsThatOnlyRepeatedSkillLoads([$unknownNameLoad, $olderRepeatedLoad, $newestRepeatedLoad])
        );
        self::assertFalse($unknownNameLoad->toolCall->onlyRepeatedASkillLoad());
        self::assertTrue($newestRepeatedLoad->toolCall->onlyRepeatedASkillLoad());
    }

    public function testTheNextStepsTailSaysTheSkillIsLoaded(): void
    {
        $agentModelInput = new AgentModelInput();
        $agentModelInput->loadedCapabilities = LoadedCapabilities::fromCanonicalEntries(['Reviews' => ['name' => 'Reviews', 'type' => LoadedCapability::TYPE_SKILL]]);
        $agentModelInput->messages = [
            $this->textRow(AIConversationMessage::ROLE_USER, 'show me my reviews', 0),
            $this->callRow('getReviews', [], 100, false),
            $this->callRow(LoadSkillsTool::NAME, ['skill_names' => ['Reviews']], 120, true),
        ];

        self::assertSame('[SIGNAL:skills] Reviews is loaded — its tools are in your tool list now: call the one you need.', $agentModelInput->loadedSkillsTailHint(), 'no loaded skill object here: no tool names');
    }

    public function testTheTailNamesOnlyTheSkillsThatAreLoaded(): void
    {
        $agentModelInput = new AgentModelInput();
        $agentModelInput->loadedCapabilities = LoadedCapabilities::fromCanonicalEntries(['Reviews' => ['name' => 'Reviews', 'type' => LoadedCapability::TYPE_SKILL]]);
        $agentModelInput->messages = [
            $this->textRow(AIConversationMessage::ROLE_USER, 'show me my reviews', 0),
            $this->callRow('getReviews', [], 100, false),
            $this->callRow(LoadSkillsTool::NAME, ['skill_names' => ['Reviews', 'Ratings']], 120, true),
        ];

        self::assertSame('[SIGNAL:skills] Reviews is loaded — its tools are in your tool list now: call the one you need.', $agentModelInput->loadedSkillsTailHint());
    }

    public function testNoTailLineNamesASkillThatIsNotLoaded(): void
    {
        $agentModelInput = new AgentModelInput();
        $agentModelInput->loadedCapabilities = LoadedCapabilities::fromCanonicalEntries(['Reviews' => ['name' => 'Reviews', 'type' => LoadedCapability::TYPE_SKILL]]);
        $agentModelInput->messages = [
            $this->textRow(AIConversationMessage::ROLE_USER, 'show me my reviews', 0),
            $this->callRow('getReviews', [], 100, false),
            $this->callRow(LoadSkillsTool::NAME, ['skill_names' => ['Posts.Seri.es']], 120, true, true),
        ];

        self::assertNull($agentModelInput->loadedSkillsTailHint());
    }

    protected function textRow(string $role, string $text, int $inputTokens): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->role = $role;
        $message->authorType = $role === AIConversationMessage::ROLE_USER ? AIConversationMessage::AUTHOR_USER : AIConversationMessage::AUTHOR_ASSISTANT;
        $message->message = $text;
        $message->inputTokens = $inputTokens;
        return $message;
    }

    /** @param array<string, mixed> $arguments */
    protected function callRow(string $toolName, array $arguments, int $inputTokens, bool $loadedNothing, bool $namedNoSkill = false): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->inputTokens = $inputTokens;
        /** @var ToolCall $toolCall */
        $toolCall = self::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = $toolName;
        $toolCall->arguments = $arguments;
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $toolCall->loadSkillsCallLoadedNothing = $loadedNothing;
        $toolCall->loadSkillsCallNamedNoSkill = $namedNoSkill;
        $message->toolCall = $toolCall;
        return $message;
    }
}
