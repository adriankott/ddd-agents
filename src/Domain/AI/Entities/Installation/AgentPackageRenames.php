<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Installation;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The renames the installed packages list in their `renames.json`, in file order per package
 * ({@see AgentPackageRename}).
 *
 * @property AgentPackageRename[] $elements
 * @method AgentPackageRename[] getElements()
 * @method AgentPackageRename|null getByUniqueKey(string $uniqueKey)
 */
class AgentPackageRenames extends ObjectSet
{
    /**
     * Adds the entries of `<package directory>/<renames file>`; nothing when the file is missing or does not parse. The
     * package name is the map's `package`, else the name of the package's composer.json; the installed version the
     * `version` of that composer.json. An entry without a regular version, kind, old or new name is skipped (Core's
     * `ddd-renames validate` reports it).
     */
    public function addRenamesOfPackageDirectory(string $packageDirectory, string $renamesFileName): void
    {
        $renamesFile = rtrim($packageDirectory, '/') . '/' . $renamesFileName;
        if (!is_file($renamesFile)) {
            return;
        }
        $renamesDocument = json_decode((string)file_get_contents($renamesFile), true);
        if (!is_array($renamesDocument) || !is_array($renamesDocument['renames'] ?? null)) {
            return;
        }
        $composerFile = rtrim($packageDirectory, '/') . '/composer.json';
        $composerDocument = is_file($composerFile) ? json_decode((string)file_get_contents($composerFile), true) : null;
        $packageName = is_string($renamesDocument['package'] ?? null)
            ? $renamesDocument['package']
            : (is_string($composerDocument['name'] ?? null) ? $composerDocument['name'] : basename($packageDirectory));
        $installedVersion = is_string($composerDocument['version'] ?? null) ? ltrim($composerDocument['version'], 'v') : null;
        foreach ($renamesDocument['renames'] as $renameEntry) {
            $agentPackageRename = $this->buildRenameOfEntry($renameEntry, $packageName, $installedVersion);
            if ($agentPackageRename !== null) {
                $this->add($agentPackageRename);
            }
        }
    }

    protected function buildRenameOfEntry(mixed $renameEntry, string $packageName, ?string $installedVersion): ?AgentPackageRename
    {
        if (!is_array($renameEntry)) {
            return null;
        }
        $version = is_string($renameEntry['version'] ?? null) ? ltrim($renameEntry['version'], 'v') : '';
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            return null;
        }
        foreach (['kind', 'old', 'new'] as $requiredKey) {
            if (!is_string($renameEntry[$requiredKey] ?? null) || $renameEntry[$requiredKey] === '') {
                return null;
            }
        }
        $agentPackageRename = AgentPackageRename::newInstance();
        $agentPackageRename->packageName = $packageName;
        $agentPackageRename->installedVersion = $installedVersion !== null && preg_match('/^\d+\.\d+\.\d+$/', $installedVersion) === 1 ? $installedVersion : null;
        $agentPackageRename->version = $version;
        $agentPackageRename->kind = $renameEntry['kind'];
        $agentPackageRename->oldName = $renameEntry['old'];
        $agentPackageRename->newName = $renameEntry['new'];
        $agentPackageRename->note = is_string($renameEntry['note'] ?? null) ? $renameEntry['note'] : null;
        return $agentPackageRename;
    }
}
