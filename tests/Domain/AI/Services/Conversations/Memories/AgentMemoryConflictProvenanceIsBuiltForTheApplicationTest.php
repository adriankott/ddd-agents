<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryConflictProvenance;
use DDD\Tests\Agents\Support\AgentPackageSourceRoots;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The provenance of a contradicted memory is built with `newInstance()`: an application that words the age of the
 * stated value its own way puts a subclass at the same path, and the conflict signal carries that class directly.
 * `new` would always give the package's class.
 */
class AgentMemoryConflictProvenanceIsBuiltForTheApplicationTest extends UnitTestCase
{
    /** @var string `new` followed by the provenance class, qualified or not */
    public const string NEW_PROVENANCE_EXPRESSION_PATTERN = '/\bnew\s+\\\\?(?:[A-Za-z_]+\\\\)*AgentMemoryConflictProvenance\s*\(/';

    public function testNoPackageClassBuildsTheProvenanceWithNew(): void
    {
        $filesBuildingWithNew = [];
        foreach (AgentPackageSourceRoots::findPhpFiles() as $relativePath => $absolutePath) {
            if (preg_match(static::NEW_PROVENANCE_EXPRESSION_PATTERN, (string)file_get_contents($absolutePath)) === 1) {
                $filesBuildingWithNew[] = $relativePath;
            }
        }
        self::assertSame([], $filesBuildingWithNew, 'use AgentMemoryConflictProvenance::newInstance(): an application may word the provenance at the same path');
    }

    public function testThePatternFindsTheFormsOfNew(): void
    {
        foreach (['new AgentMemoryConflictProvenance()', 'new \\DDD\\Domain\\AI\\Entities\\Conversations\\Memories\\Curation\\AgentMemoryConflictProvenance()'] as $newExpression) {
            self::assertSame(1, preg_match(static::NEW_PROVENANCE_EXPRESSION_PATTERN, $newExpression), $newExpression);
        }
        self::assertSame(0, preg_match(static::NEW_PROVENANCE_EXPRESSION_PATTERN, 'AgentMemoryConflictProvenance::newInstance()'));
    }

    public function testWithoutAnApplicationClassTheProvenanceIsThePackagesClass(): void
    {
        self::assertSame(AgentMemoryConflictProvenance::class, AgentMemoryConflictProvenance::newInstance()::class);
    }
}
