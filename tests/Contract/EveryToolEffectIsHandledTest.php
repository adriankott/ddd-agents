<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Domain\AI\Entities\Conversations\Messages\ToolCallEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolAnnotations;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Infrastructure\Validation\Constraints\Choice;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Every effect value of {@see MCPToolEffect} is handled by the readers of an effect, none falls back silently: it has a
 * place in the order of harm, a stored call can carry it ({@see ToolCallEffect}), it belongs to exactly one class of the
 * predicates the consumers ask (read, workspace change, state change) and it renders MCP annotations. The consumers that
 * decide by these predicates: the audit's write ledger and read marker, the run counts, the paused-turn record, the
 * record-change check, the identical-write refusal, the per-turn read reuse and the final-refusal scan, the fork-join,
 * the supervisor, the compaction telemetry, the eval read-only gate and the environment-wide write lock, the eval
 * lanes, the MCP annotations. A new value fails here until each of them is decided for it.
 */
class EveryToolEffectIsHandledTest extends UnitTestCase
{
    /** @return string[] The effect values: the string constants of MCPToolEffect */
    protected function findEffectValues(): array
    {
        $effectValues = [];
        foreach ((new ReflectionClass(MCPToolEffect::class))->getConstants() as $constantValue) {
            if (is_string($constantValue)) {
                $effectValues[] = $constantValue;
            }
        }
        return $effectValues;
    }

    public function testEveryEffectValueIsHandled(): void
    {
        $storableEffects = (new ReflectionProperty(ToolCallEffect::class, 'effect'))->getAttributes(Choice::class)[0]->newInstance()->choices;
        self::assertSame([MCPToolEffect::READ, MCPToolEffect::WORKSPACE, MCPToolEffect::WRITE, MCPToolEffect::DELETE], $this->findEffectValues());
        foreach ($this->findEffectValues() as $effectValue) {
            $mcpToolEffect = new MCPToolEffect($effectValue);
            self::assertArrayHasKey($effectValue, MCPToolEffect::HARM_RANK_BY_EFFECT, $effectValue . ' has a place in the order of harm');
            self::assertContains($effectValue, $storableEffects, $effectValue . ' can be stored on a tool call');
            $predicateClasses = array_filter([$mcpToolEffect->isReadOnly(), $mcpToolEffect->changesOnlyTheWorkspace(), $mcpToolEffect->changesState()]);
            self::assertCount(1, $predicateClasses, $effectValue . ' is exactly one of read, workspace change, state change');
            self::assertNotNull(MCPToolAnnotations::fromToolDeclarations($mcpToolEffect, false), $effectValue . ' renders MCP annotations');
            self::assertSame($effectValue, ToolCallEffect::fromMcpToolEffect($mcpToolEffect)->toMcpToolEffect()?->effect, $effectValue . ' survives the stored copy');
        }
    }

    public function testTheOrderOfHarm(): void
    {
        $harmRanks = [];
        foreach ([MCPToolEffect::READ, MCPToolEffect::WORKSPACE, MCPToolEffect::WRITE, MCPToolEffect::DELETE] as $effectValue) {
            $harmRanks[] = (new MCPToolEffect($effectValue))->getHarmRank();
        }
        self::assertSame([0, 1, 2, 3], $harmRanks);
        self::assertSame(3, (new MCPToolEffect('UNKNOWN'))->getHarmRank(), 'an unknown effect ranks as the most harmful');
    }
}
