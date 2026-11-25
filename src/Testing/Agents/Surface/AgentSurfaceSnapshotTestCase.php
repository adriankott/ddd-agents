<?php

declare(strict_types=1);

namespace DDD\Testing\Agents\Surface;

use PHPUnit\Framework\TestCase;

/**
 * Pins what an application's agent is offered ({@see AgentSurfaceDocumentBuilder}) as a test every application suite
 * runs under its own container, config and bindings:
 *
 *   class AgentSurfaceSnapshotTest extends \DDD\Testing\Agents\Surface\AgentSurfaceSnapshotTestCase
 *   {
 *       protected function getPinnedSurfaceFile(): string { return __DIR__ . '/Fixtures/agent-surface.json'; }
 *       protected function getAcceptedDifferencesFile(): ?string { return __DIR__ . '/Fixtures/agent-surface-accepted-differences.json'; }
 *       protected function findPurposeNames(): array { return $this->findPurposeNamesInDirectory(dirname(__DIR__, 2) . '/config/app/AI/Purposes'); }
 *   }
 *
 * Two tests, which together cover every entry of the application's surface once:
 * - {@see testThePackagesSurfaceDiffersOnlyWhereTheApplicationAcceptedIt()}: the entries the agent package itself
 *   ships (its skills, packages and purposes, {@see getPackageSurfaceFile()}) against the package's own surface. What
 *   differs is what the application changed (an overlay, a rights mapping, a renamed argument, a subclass at the path
 *   of a package class) and must be exactly the accepted list: path => the application's value. A package release that
 *   changes its own tool moves both sides, so the application's file changes only where its own change meets the
 *   package's.
 * - {@see testTheOfferedSurfaceIsThePinnedOne()}: the application's own entries against its pinned file. While the
 *   first test runs, the pinned file leaves out the entries the package ships ({@see buildSurfaceDocumentToPin()})
 *   and keeps the application's own entries and the order of its catalog (`skillOrder`); an
 *   application that names no file of accepted differences, or whose installed release ships no surface, pins its
 *   whole surface here. A failure names every value that changed by its path
 *   (`packages → X → tools → y → description`), not a file hash.
 *
 * Both files are written by a run with the environment variable {@see REGENERATE_ENVIRONMENT_VARIABLE} set; the run
 * is then reported as skipped. The application's test bootstrap boots its kernel; this class boots nothing.
 */
abstract class AgentSurfaceSnapshotTestCase extends TestCase
{
    /** Set to rewrite the pinned surface and the accepted differences instead of comparing */
    public const string REGENERATE_ENVIRONMENT_VARIABLE = 'REGENERATE_AGENT_SURFACE_SNAPSHOT';

    /** The agent package's own surface, written by the package's suite and shipped beside this class */
    public const string PACKAGE_SURFACE_FILE = __DIR__ . '/agent-package-surface.json';

    /** @var array<class-string, array<string, mixed>> The surface document of a test class as its tree ({@see AgentSurfaceDocument::toArray()}), built once */
    protected static array $surfaceDocumentsByTestClass = [];

    /**
     * @return string The file that pins the application's surface
     */
    abstract protected function getPinnedSurfaceFile(): string;

    /**
     * @return string|null The file that lists where the application's surface differs from the agent package's own;
     * null = the comparison with the package's surface is not run and the pinned file holds the whole surface
     */
    protected function getAcceptedDifferencesFile(): ?string
    {
        return null;
    }

    /**
     * @return string|null The agent package's own surface; null when the installed release ships none
     */
    protected function getPackageSurfaceFile(): ?string
    {
        return is_file(static::PACKAGE_SURFACE_FILE) ? static::PACKAGE_SURFACE_FILE : null;
    }

    /**
     * @return string[] The purposes of the application to pin, as dotted names ({@see findPurposeNamesInDirectory()})
     */
    protected function findPurposeNames(): array
    {
        return [];
    }

    /**
     * @return string[] The HTTP MCP bundle classes of the application to pin
     */
    protected function findBundleClasses(): array
    {
        return [];
    }

    public function testTheOfferedSurfaceIsThePinnedOne(): void
    {
        $surfaceDocumentToPin = $this->buildSurfaceDocumentToPin();
        $pinnedSurfaceFile = $this->getPinnedSurfaceFile();
        if ($this->isRegenerationRequested()) {
            $this->writeJsonFile($pinnedSurfaceFile, $surfaceDocumentToPin);
            self::markTestSkipped('The pinned surface was written: ' . $pinnedSurfaceFile);
        }
        self::assertFileExists($pinnedSurfaceFile, sprintf('No pinned surface yet. Write it with %s=1.', static::REGENERATE_ENVIRONMENT_VARIABLE));
        $differences = (new AgentSurfaceDocumentComparer())->compare($this->readJsonFile($pinnedSurfaceFile), $surfaceDocumentToPin);
        self::assertCount(
            0,
            $differences,
            sprintf(
                "The offered surface changed in %d value(s) (- pinned only, + now only, ~ pinned => now). Intended: rewrite the file with %s=1.\n%s",
                count($differences),
                static::REGENERATE_ENVIRONMENT_VARIABLE,
                $differences->describe()
            )
        );
    }

    public function testThePackagesSurfaceDiffersOnlyWhereTheApplicationAcceptedIt(): void
    {
        $acceptedDifferencesFile = $this->getAcceptedDifferencesFile();
        $packageSurfaceFile = $this->getPackageSurfaceFile();
        if ($acceptedDifferencesFile === null) {
            self::markTestSkipped('The application names no file of accepted differences.');
        }
        if ($packageSurfaceFile === null) {
            self::markTestSkipped('The installed agent package ships no surface of its own.');
        }
        $agentSurfaceDocumentComparer = new AgentSurfaceDocumentComparer();
        $differencesToThePackage = $agentSurfaceDocumentComparer->buildDifferencesToEntriesOf(
            $this->readJsonFile($packageSurfaceFile),
            $this->getCurrentSurfaceDocument()
        );
        if ($this->isRegenerationRequested()) {
            $this->writeJsonFile($acceptedDifferencesFile, $differencesToThePackage);
            self::markTestSkipped('The accepted differences were written: ' . $acceptedDifferencesFile);
        }
        self::assertFileExists($acceptedDifferencesFile, sprintf('No accepted differences yet. Write them with %s=1.', static::REGENERATE_ENVIRONMENT_VARIABLE));
        $unacceptedDifferences = $agentSurfaceDocumentComparer->compare($this->readJsonFile($acceptedDifferencesFile), $differencesToThePackage);
        self::assertCount(
            0,
            $unacceptedDifferences,
            sprintf(
                "Where the application differs from the agent package's own surface changed in %d value(s) (+ a new difference, - an accepted one that is gone, ~ accepted => now; '%s' = the application does not have the value). Intended: rewrite the file with %s=1.\n%s",
                count($unacceptedDifferences),
                AgentSurfaceDocumentComparer::ABSENT,
                static::REGENERATE_ENVIRONMENT_VARIABLE,
                $unacceptedDifferences->describe()
            )
        );
    }

    /**
     * @return bool Whether the entries the agent package ships are compared with the package's own surface
     */
    protected function isComparedWithThePackagesSurface(): bool
    {
        return $this->getAcceptedDifferencesFile() !== null && $this->getPackageSurfaceFile() !== null;
    }

    /**
     * The part of the application's surface its pinned file holds: the whole surface, or, while the entries the agent
     * package ships are compared with the package's own surface, the application's own entries only.
     *
     * @return array<string, mixed>
     */
    protected function buildSurfaceDocumentToPin(): array
    {
        $currentSurfaceDocument = $this->getCurrentSurfaceDocument();
        if (!$this->isComparedWithThePackagesSurface()) {
            return $currentSurfaceDocument;
        }
        return (new AgentSurfaceDocumentComparer())->withoutEntriesOf(
            $currentSurfaceDocument,
            $this->readJsonFile((string)$this->getPackageSurfaceFile())
        );
    }

    /**
     * Every purpose file below a purposes directory (`<directory>/<group>/<name>.md`) as a dotted name, sorted.
     *
     * @return string[]
     */
    protected function findPurposeNamesInDirectory(string $purposesDirectory): array
    {
        $purposeNames = [];
        foreach (glob($purposesDirectory . '/*/*.md') ?: [] as $purposeFile) {
            $purposeNames[] = basename(dirname($purposeFile)) . '.' . basename($purposeFile, '.md');
        }
        sort($purposeNames);
        return $purposeNames;
    }

    /**
     * @return array<string, mixed> The surface of the running application, built once per test class
     */
    protected function getCurrentSurfaceDocument(): array
    {
        if (!isset(static::$surfaceDocumentsByTestClass[static::class])) {
            static::$surfaceDocumentsByTestClass[static::class] = $this->createSurfaceDocumentBuilder()->buildSurfaceDocument(
                $this->findPurposeNames(),
                $this->findBundleClasses()
            )->toArray();
        }
        return static::$surfaceDocumentsByTestClass[static::class];
    }

    protected function createSurfaceDocumentBuilder(): AgentSurfaceDocumentBuilder
    {
        return new AgentSurfaceDocumentBuilder();
    }

    protected function isRegenerationRequested(): bool
    {
        $regenerationRequest = getenv(static::REGENERATE_ENVIRONMENT_VARIABLE);
        return $regenerationRequest !== false && $regenerationRequest !== '' && $regenerationRequest !== '0';
    }

    /**
     * @return array<string, mixed>
     */
    protected function readJsonFile(string $filePath): array
    {
        $decodedContent = json_decode((string)file_get_contents($filePath), true, 512, JSON_THROW_ON_ERROR);
        return is_array($decodedContent) ? $decodedContent : [];
    }

    /**
     * @param array<string, mixed> $content
     */
    protected function writeJsonFile(string $filePath, array $content): void
    {
        file_put_contents(
            $filePath,
            json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"
        );
    }
}
