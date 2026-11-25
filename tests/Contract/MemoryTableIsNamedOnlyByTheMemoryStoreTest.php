<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Tests\Agents\Support\AgentPackageSourceRoots;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The memory store ({@see \DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService}) is the host port of
 * the memory: a host replaces it by a store over rows of its own. So no class of the package outside the store, its
 * repositories and its entities names the package's memory model or table — a second reader would bypass the host's
 * store and read a table the host does not have.
 */
class MemoryTableIsNamedOnlyByTheMemoryStoreTest extends UnitTestCase
{
    /** @var string[] Paths below src/ that may name the memory model (prefixes) */
    public const array ALLOWED_PATH_PREFIXES = [
        'Domain/AI/Services/Conversations/Memories/AgentMemoryStoreService.php',
        'Domain/AI/Repo/DB/Conversations/Memories/',
        'Domain/AI/Entities/Conversations/Memories/',
    ];

    public function testOnlyTheStoreItsRepositoriesAndItsEntitiesNameTheMemoryModel(): void
    {
        $filesNamingTheMemoryModel = [];
        foreach (AgentPackageSourceRoots::findPhpFiles() as $relativePath => $absolutePath) {
            if ($this->isAllowedPath($relativePath)) {
                continue;
            }
            $sourceText = (string)file_get_contents($absolutePath);
            if (preg_match('/\bDBAgentMemor(y|ies)(Model)?\b|`AgentMemories`|[\'"]AgentMemories[\'"]/', $sourceText) === 1) {
                $filesNamingTheMemoryModel[] = $relativePath;
            }
        }

        self::assertSame([], $filesNamingTheMemoryModel, 'ask the memory store (AgentMemoryStoreService) instead of naming its table');
    }

    protected function isAllowedPath(string $relativePath): bool
    {
        foreach (static::ALLOWED_PATH_PREFIXES as $allowedPathPrefix) {
            if (str_starts_with($relativePath, $allowedPathPrefix)) {
                return true;
            }
        }
        return false;
    }
}
