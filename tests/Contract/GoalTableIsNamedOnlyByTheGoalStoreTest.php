<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Tests\Agents\Support\AgentPackageSourceRoots;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The goal store ({@see \DDD\Domain\AI\Services\Conversations\Goals\AgentGoalStoreService}) is the host port of the
 * goal register: a host replaces it by a store over rows of its own. So no class of the package outside the store, its
 * repositories and its entities names the package's goal model or table — a second reader would bypass the host's
 * store and read a table the host does not have.
 */
class GoalTableIsNamedOnlyByTheGoalStoreTest extends UnitTestCase
{
    /** @var string[] Paths below src/ that may name the goal model (prefixes) */
    public const array ALLOWED_PATH_PREFIXES = [
        'Domain/AI/Services/Conversations/Goals/AgentGoalStoreService.php',
        'Domain/AI/Repo/DB/Conversations/Goals/',
        'Domain/AI/Entities/Conversations/Goals/',
    ];

    public function testOnlyTheStoreItsRepositoriesAndItsEntitiesNameTheGoalModel(): void
    {
        $filesNamingTheGoalModel = [];
        foreach (AgentPackageSourceRoots::findPhpFiles() as $relativePath => $absolutePath) {
            if ($this->isAllowedPath($relativePath)) {
                continue;
            }
            $sourceText = (string)file_get_contents($absolutePath);
            if (preg_match('/\bDBAgentGoals?(Model)?\b|`AgentGoals`|[\'"]AgentGoals[\'"]/', $sourceText) === 1) {
                $filesNamingTheGoalModel[] = $relativePath;
            }
        }

        self::assertSame([], $filesNamingTheGoalModel, 'ask the goal store (AgentGoalStoreService) instead of naming its table');
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
