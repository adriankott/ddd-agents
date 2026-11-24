<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegment;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegmentType;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentPreviousReasoningCut;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A call that moves the reasoning cut marks the entry before the earlier cut (read: the cut call before wrote it) and
 * the entry before the new cut (written for the next cut call); a call that does not move the cut marks as before.
 */
class PreviousReasoningCutCacheMarkersTest extends UnitTestCase
{
    public function testACutCallMarksTheEntriesBeforeBothCuts(): void
    {
        $argusAIAgentConversation = static::buildConversationWithCut(true, 2, 6);
        $wireMessages = static::buildWireMessages();

        self::assertTrue($argusAIAgentConversation->addPromptCacheBreakpointsAtPreviousReasoningCut($wireMessages, true));

        self::assertSame(['1'], static::findMarkedTexts($wireMessages, [0, 1]), 'before the earlier cut: the tool result at 1');
        self::assertSame(['5'], static::findMarkedTexts($wireMessages, [2, 3, 4, 5]), 'before the new cut: the tool result at 5, not the assistant entry at 4');
    }

    public function testOnTheMessagesApiAnAssistantEntryMayCarryTheMarker(): void
    {
        $argusAIAgentConversation = static::buildConversationWithCut(true, 2, 5);
        $wireMessages = static::buildWireMessages();

        $argusAIAgentConversation->addPromptCacheBreakpointsAtPreviousReasoningCut($wireMessages, false);

        self::assertSame(['4'], static::findMarkedTexts($wireMessages, [2, 3, 4]));
    }

    public function testACallThatKeepsTheCutMarksNothingHere(): void
    {
        $argusAIAgentConversation = static::buildConversationWithCut(false, 2, null);
        $wireMessages = static::buildWireMessages();

        self::assertFalse($argusAIAgentConversation->addPromptCacheBreakpointsAtPreviousReasoningCut($wireMessages, true));
        self::assertStringNotContainsString('prompt_cache_breakpoint', json_encode($wireMessages));
    }

    public function testACutCallLeavesOnlyTheSystemEndMarked(): void
    {
        $argusAIAgentConversation = static::buildConversationWithCut(true, 2, 6);
        $systemContentParts = $argusAIAgentConversation->buildSystemContentPartsWithPromptCacheBreakpoints([
            static::segment(AgentContextSegmentType::BASE_PROMPT, 'persona '),
            static::segment(AgentContextSegmentType::SKILL_CATALOG, 'catalog '),
            static::segment(AgentContextSegmentType::LOADED_SKILLS, 'skills '),
        ], false, false);

        self::assertSame([['type' => 'text', 'text' => 'persona catalog skills ', 'prompt_cache_breakpoint' => ArgusAIAgentConversation::PROMPT_CACHE_BREAKPOINT]], $systemContentParts);
    }

    protected static function buildConversationWithCut(bool $movedOnThisCall, ?int $anchorWirePosition, ?int $newCutWirePosition): ArgusAIAgentConversation
    {
        /** @var ArgusAIAgentConversation $argusAIAgentConversation */
        $argusAIAgentConversation = self::instantiateWithoutConstructor(ArgusAIAgentConversation::class);
        $agentPreviousReasoningCut = new AgentPreviousReasoningCut();
        $agentPreviousReasoningCut->movedOnThisCall = $movedOnThisCall;
        $argusAIAgentConversation->lastPreviousReasoningCut = $agentPreviousReasoningCut;
        $argusAIAgentConversation->previousReasoningAnchorCutWirePosition = $anchorWirePosition;
        $argusAIAgentConversation->previousReasoningNewCutWirePosition = $newCutWirePosition;
        $argusAIAgentConversation->stableWireMessageCount = 7;
        return $argusAIAgentConversation;
    }

    /** @return array<int, array<string, mixed>> */
    protected static function buildWireMessages(): array
    {
        return [
            ['role' => 'user', 'content' => '0'],
            ['role' => 'tool', 'content' => '1'],
            ['role' => 'assistant', 'content' => '2'],
            ['role' => 'tool', 'content' => '3'],
            ['role' => 'assistant', 'content' => '4'],
            ['role' => 'tool', 'content' => '5'],
            ['role' => 'assistant', 'content' => '6'],
            ['role' => 'user', 'content' => 'volatile tail'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $wireMessages
     * @param int[] $positions
     * @return string[]
     */
    protected static function findMarkedTexts(array $wireMessages, array $positions): array
    {
        $markedTexts = [];
        foreach ($positions as $position) {
            foreach ((array)$wireMessages[$position]['content'] as $contentPart) {
                if (is_array($contentPart) && isset($contentPart['prompt_cache_breakpoint'])) {
                    $markedTexts[] = $contentPart['text'];
                }
            }
        }
        return $markedTexts;
    }

    protected static function segment(AgentContextSegmentType $agentContextSegmentType, string $text): AgentContextSegment
    {
        $agentContextSegment = new AgentContextSegment();
        $agentContextSegment->type = $agentContextSegmentType;
        $agentContextSegment->text = $text;
        return $agentContextSegment;
    }
}
