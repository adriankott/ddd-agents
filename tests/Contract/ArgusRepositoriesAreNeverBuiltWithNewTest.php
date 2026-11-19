<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Tests\Agents\Support\AgentPackageSourceRoots;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * No class of the package builds an Argus repository (the model egress of the agent, its decisions, summaries, reranks
 * and judgments) with `new`.
 *
 * An application may put a subclass of such a repository at the same path under its own root namespace — the agent's
 * egress `ArgusAIAgentConversation` above all (its endpoint, routing, cache key, effort). `X::newInstance()` resolves
 * the application's class through `DDDService::getContainerServiceClassNameForClass()`; `new X()` always gives the
 * package's class, and the application's subclass is never reached.
 */
class ArgusRepositoriesAreNeverBuiltWithNewTest extends UnitTestCase
{
    /** @var string `new` followed by a (possibly qualified) class whose short name starts with `Argus` */
    public const string NEW_ARGUS_EXPRESSION_PATTERN = '/\bnew\s+\\\\?(?:[A-Za-z_]+\\\\)*(Argus[A-Za-z0-9_]*)\s*\(/';

    public function testNoPackageClassBuildsAnArgusRepositoryWithNew(): void
    {
        $filesBuildingWithNew = [];
        foreach (AgentPackageSourceRoots::findPhpFiles() as $relativePath => $absolutePath) {
            if (preg_match_all(static::NEW_ARGUS_EXPRESSION_PATTERN, (string)file_get_contents($absolutePath), $matches) > 0) {
                $filesBuildingWithNew[] = $relativePath . ': new ' . implode(', new ', array_unique($matches[1]));
            }
        }

        self::assertSame([], $filesBuildingWithNew, 'use X::newInstance(): an application may subclass an Argus repository at the same path');
    }

    public function testThePatternFindsTheFormsOfNew(): void
    {
        foreach (['new ArgusAIAgentConversation()', 'new \\DDD\\Domain\\AI\\Repo\\Argus\\Conversations\\ArgusAIAgentConversation()', 'new ArgusSearchRerank ()'] as $newExpression) {
            self::assertSame(1, preg_match(static::NEW_ARGUS_EXPRESSION_PATTERN, $newExpression), $newExpression);
        }
        self::assertSame(0, preg_match(static::NEW_ARGUS_EXPRESSION_PATTERN, 'ArgusAIAgentConversation::newInstance()'));
    }
}
