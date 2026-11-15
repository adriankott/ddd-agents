<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\ComposedViewNodeSchemaFilePaths;
use DDD\Symfony\Commands\Agents\ComposedViewNodeSchemaExportCommand;

/** The export command with the schema file paths a test names. */
class ComposedViewNodeSchemaExportProbeCommand extends ComposedViewNodeSchemaExportCommand
{
    public function __construct(protected ComposedViewNodeSchemaFilePaths $composedViewNodeSchemaFilePaths)
    {
        parent::__construct('app:ai:composed-view:export-node-schema');
    }

    protected function getSchemaFilePaths(): ComposedViewNodeSchemaFilePaths
    {
        return $this->composedViewNodeSchemaFilePaths;
    }
}
