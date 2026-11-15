<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Where the two committed files of the `ComposedView` node schema lie ({@see ComposedViewNodeSchemaParts}):
 *
 * - {@see $moduleSchemaFilePath}: the module's schema, which the installed module ships with its frontend — compared,
 *   never written;
 * - {@see $applicationSchemaPartFilePath}: the application's part, written (or, in a check, compared) — null when the
 *   application keeps no such file;
 * - {@see $displayRootDirectory}: the directory the printed paths are relative to.
 *
 * @phpstan-consistent-constructor
 */
class ComposedViewNodeSchemaFilePaths extends ValueObject
{
    /** @var string The module's schema file below the module's root directory */
    public const string MODULE_SCHEMA_FILE_RELATIVE_PATH = 'web/src/renderer/widget-tree/composed-node-schema.json';

    /** @var string|null The absolute path of the module's schema file; null = not compared */
    public ?string $moduleSchemaFilePath = null;

    /** @var string|null The absolute path of the application's part; null = the application keeps none */
    public ?string $applicationSchemaPartFilePath = null;

    /** @var string The directory the printed paths are relative to, without a trailing slash */
    public string $displayRootDirectory = '';

    /**
     * @param string|null $moduleSchemaFilePath
     * @param string|null $applicationSchemaPartFilePath
     * @param string $displayRootDirectory
     * @return static
     */
    public static function forPaths(?string $moduleSchemaFilePath, ?string $applicationSchemaPartFilePath, string $displayRootDirectory): static
    {
        $composedViewNodeSchemaFilePaths = new static();
        $composedViewNodeSchemaFilePaths->moduleSchemaFilePath = $moduleSchemaFilePath;
        $composedViewNodeSchemaFilePaths->applicationSchemaPartFilePath = $applicationSchemaPartFilePath;
        $composedViewNodeSchemaFilePaths->displayRootDirectory = rtrim($displayRootDirectory, '/');
        return $composedViewNodeSchemaFilePaths;
    }

    /**
     * `$filePath` relative to {@see $displayRootDirectory}; as given when it lies outside it.
     *
     * @param string $filePath
     * @return string
     */
    public function getDisplayPath(string $filePath): string
    {
        $displayRootPrefix = $this->displayRootDirectory . '/';
        return $this->displayRootDirectory !== '' && str_starts_with($filePath, $displayRootPrefix)
            ? substr($filePath, strlen($displayRootPrefix))
            : $filePath;
    }
}
