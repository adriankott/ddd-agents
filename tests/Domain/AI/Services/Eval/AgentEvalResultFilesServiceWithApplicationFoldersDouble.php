<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;

/**
 * An application's result files service: it keeps a dated folder of its own in the results folder
 * (`seed-<yyyymmdd>-…`) and lets the retention sweep delete it like a run folder.
 */
class AgentEvalResultFilesServiceWithApplicationFoldersDouble extends AgentEvalResultFilesService
{
    /** @var string The name of the application's own dated folders */
    public const string APPLICATION_SEED_FOLDER_NAME_PATTERN = '/^seed-(?<year>20\d{2})(?<month>\d{2})(?<day>\d{2})-/';

    /** @return string[] */
    public function getRetainedFolderNamePatterns(): array
    {
        return array_merge(parent::getRetainedFolderNamePatterns(), [static::APPLICATION_SEED_FOLDER_NAME_PATTERN]);
    }
}
