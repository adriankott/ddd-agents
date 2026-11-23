<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegment;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegmentType;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A Claude model caches only at the `cache_control` markers a request carries: the agent marks the system content at
 * the boundaries of the explicit breakpoints and the newest stable wire message, never more than four markers.
 */
class AnthropicCacheControlMarkersTest extends UnitTestCase
{
    public function testTheSystemBoundariesAndTheStableMessageCarryCacheControl(): void
    {
        /** @var ArgusAIAgentConversation $argusAIAgentConversation */
        $argusAIAgentConversation = self::instantiateWithoutConstructor(ArgusAIAgentConversation::class);
        $systemContentParts = $argusAIAgentConversation->buildSystemContentPartsWithPromptCacheBreakpoints([
            static::segment(AgentContextSegmentType::BASE_PROMPT, 'persona '),
            static::segment(AgentContextSegmentType::SKILL_CATALOG, 'catalog '),
            static::segment(AgentContextSegmentType::LOADED_SKILLS, 'skills '),
        ]);
        $wireMessages = [
            ['role' => 'system', 'content' => $systemContentParts],
            ['role' => 'user', 'content' => 'first question'],
            ['role' => 'assistant', 'content' => 'answer'],
            ['role' => 'user', 'content' => 'volatile tail'],
        ];
        $argusAIAgentConversation->addPromptCacheBreakpointToLastTextMessageBefore($wireMessages, 3, true);

        $converted = $argusAIAgentConversation->convertPromptCacheBreakpointsToAnthropicCacheControl($wireMessages);

        self::assertSame([
            ['type' => 'text', 'text' => 'persona ', 'cache_control' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => 'catalog ', 'cache_control' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => 'skills ', 'cache_control' => ['type' => 'ephemeral']],
        ], $converted[0]['content']);
        self::assertSame([['type' => 'text', 'text' => 'first question', 'cache_control' => ['type' => 'ephemeral']]], $converted[1]['content']);
        self::assertSame('volatile tail', $converted[3]['content']);
        self::assertStringNotContainsString('prompt_cache_breakpoint', json_encode($converted));
    }

    public function testNoMoreThanFourMarkersAreSent(): void
    {
        /** @var ArgusAIAgentConversation $argusAIAgentConversation */
        $argusAIAgentConversation = self::instantiateWithoutConstructor(ArgusAIAgentConversation::class);
        $markedPart = ['type' => 'text', 'text' => 'x', 'prompt_cache_breakpoint' => ArgusAIAgentConversation::PROMPT_CACHE_BREAKPOINT];
        $wireMessages = [['role' => 'system', 'content' => array_fill(0, 6, $markedPart)]];

        $converted = $argusAIAgentConversation->convertPromptCacheBreakpointsToAnthropicCacheControl($wireMessages);

        $markerCount = count(array_filter($converted[0]['content'], static fn (array $part): bool => isset($part['cache_control'])));
        self::assertSame(ArgusAIAgentConversation::ANTHROPIC_MAX_CACHE_MARKERS, $markerCount);
        self::assertStringNotContainsString('prompt_cache_breakpoint', json_encode($converted));
    }

    protected static function segment(AgentContextSegmentType $agentContextSegmentType, string $text): AgentContextSegment
    {
        $agentContextSegment = new AgentContextSegment();
        $agentContextSegment->type = $agentContextSegmentType;
        $agentContextSegment->text = $text;
        return $agentContextSegment;
    }
}
