<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Installation;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One rename an installed package lists in its `renames.json` (Core's format 1: `version`, `kind`, `old`, `new`,
 * `note`), with the package's name and installed version — read by the installation check
 * ({@see \DDD\Domain\AI\Services\Installation\AgentInstallationCheckService}). Applying renames is Core's
 * `vendor/bin/ddd-renames`; the check only reports.
 */
class AgentPackageRename extends ValueObject
{
    public const string KIND_CONFIG_KEY = 'configKey';

    /** @var string The Composer name of the package that made the rename */
    public string $packageName = '';

    /** @var string|null The installed version of the package (its composer.json); null = unknown */
    public ?string $installedVersion = null;

    /** @var string The release that made the rename (`MAJOR.MINOR.PATCH`) */
    public string $version = '';

    /** @var string `skillName`, `constant`, `class`, `namespace`, `configKey`, `consoleCommand`, `packageId`, `envVar` */
    public string $kind = '';

    /** @var string The old name */
    public string $oldName = '';

    /** @var string The new name */
    public string $newName = '';

    /** @var string|null The note of the entry */
    public ?string $note = null;

    /** Two renames are kept apart even with the same content (a map lists every entry once anyway) */
    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)spl_object_id($this));
    }
}
