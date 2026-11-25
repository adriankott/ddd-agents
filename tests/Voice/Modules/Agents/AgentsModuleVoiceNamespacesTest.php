<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Modules\Agents;

use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\AI\Services\Voice\Calls\AgentVoiceCallEntityStoreService;
use DDD\Domain\AI\Services\Voice\Installation\AgentVoiceFeatureTableConditionProviderService;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Modules\Agents\AgentsModule;
use DDD\Testing\Modules\ModuleNamespaceChecker;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The voice rows of {@see AgentsModule}: every `Voice\` folder of the layers lies below exactly one owned namespace
 * (five of them are owned namespaces of their own, the other three sit below namespaces the module owned already),
 * every voice class of `src/` lies below one, no voice row is without a class, the voice services are public and
 * resolve from the container, and the voice config lies in the module's config root. The generic checks over every
 * owned namespace (no class shipped by two packages, every class in an owned namespace) are the package's
 * `Contract\ModuleNamespacesTest` and `Contract\OwnedNamespacesTest`.
 */
class AgentsModuleVoiceNamespacesTest extends AgentVoiceIntegrationTestCase
{
    /** @var string[] The owned namespaces the voice channel added to the module */
    public const array VOICE_OWNED_NAMESPACES = [
        'DDD\\Domain\\AI\\Entities\\Voice\\',
        'DDD\\Domain\\AI\\Exceptions\\Voice\\',
        'DDD\\Domain\\AI\\Repo\\DB\\Voice\\',
        'DDD\\Domain\\AI\\Services\\Voice\\',
        'DDD\\Presentation\\Api\\Admin\\Voice\\',
    ];

    /** @return array<string, array{string, string}> every voice folder => the owned namespace it lies below */
    public static function provideVoiceFolders(): array
    {
        return [
            'entities' => ['DDD\\Domain\\AI\\Entities\\Voice\\', 'DDD\\Domain\\AI\\Entities\\Voice\\'],
            'exceptions' => ['DDD\\Domain\\AI\\Exceptions\\Voice\\', 'DDD\\Domain\\AI\\Exceptions\\Voice\\'],
            'message handlers' => ['DDD\\Domain\\AI\\MessageHandlers\\Voice\\', 'DDD\\Domain\\AI\\MessageHandlers\\'],
            'database repositories' => ['DDD\\Domain\\AI\\Repo\\DB\\Voice\\', 'DDD\\Domain\\AI\\Repo\\DB\\Voice\\'],
            'services' => ['DDD\\Domain\\AI\\Services\\Voice\\', 'DDD\\Domain\\AI\\Services\\Voice\\'],
            'the admin list of calls' => ['DDD\\Presentation\\Api\\Admin\\Voice\\', 'DDD\\Presentation\\Api\\Admin\\Voice\\'],
            'the voice routes' => ['DDD\\Presentation\\Api\\Agents\\Voice\\', 'DDD\\Presentation\\Api\\Agents\\'],
            'the voice tools' => ['DDD\\Presentation\\Mcp\\Client\\Voice\\', 'DDD\\Presentation\\Mcp\\'],
        ];
    }

    #[DataProvider('provideVoiceFolders')]
    public function testEveryVoiceFolderLiesBelowExactlyOneOwnedNamespace(string $voiceFolderNamespace, string $expectedOwnedNamespace): void
    {
        static::assertSame([$expectedOwnedNamespace], static::findOwnedNamespacesOf($voiceFolderNamespace));
    }

    public function testTheVoiceRowsAreOwnedNamespacesEndingInTheVoiceFolder(): void
    {
        foreach (static::VOICE_OWNED_NAMESPACES as $voiceOwnedNamespace) {
            static::assertContains($voiceOwnedNamespace, AgentsModule::getOwnedNamespaces());
            static::assertStringEndsWith('\\Voice\\', $voiceOwnedNamespace);
        }
        static::assertSame(AgentsModule::OWNED_NAMESPACES, AgentsModule::getOwnedNamespaces());
    }

    public function testEveryVoiceClassOfTheSourceLiesBelowAnOwnedNamespace(): void
    {
        $voiceClassNamesOutsideOwnedNamespaces = [];
        foreach (static::findVoiceClassNamesOfTheSource() as $voiceClassName) {
            if (static::findOwnedNamespacesOf($voiceClassName) === []) {
                $voiceClassNamesOutsideOwnedNamespaces[] = $voiceClassName;
            }
        }

        static::assertNotSame([], static::findVoiceClassNamesOfTheSource());
        static::assertSame([], $voiceClassNamesOutsideOwnedNamespaces);
    }

    public function testNoVoiceRowIsWithoutAClass(): void
    {
        $classCountsByVoiceOwnedNamespace = array_fill_keys(static::VOICE_OWNED_NAMESPACES, 0);
        foreach (static::findVoiceClassNamesOfTheSource() as $voiceClassName) {
            foreach (static::VOICE_OWNED_NAMESPACES as $voiceOwnedNamespace) {
                if (str_starts_with($voiceClassName, $voiceOwnedNamespace)) {
                    $classCountsByVoiceOwnedNamespace[$voiceOwnedNamespace]++;
                }
            }
        }

        static::assertSame([], array_keys(array_filter($classCountsByVoiceOwnedNamespace, static fn (int $classCount): bool => $classCount === 0)));
    }

    public function testNoClassIsLeftInTheNamespacesOfTheFormerModuleAndCommand(): void
    {
        $leftClassNames = [];
        foreach ((new ModuleNamespaceChecker())->findDeclaredClassNames(static::getSourceDirectory()) as $className) {
            if (str_starts_with($className, 'DDD\\Modules\\AgentVoice\\') || str_starts_with($className, 'DDD\\Symfony\\Commands\\Voice\\')) {
                $leftClassNames[] = $className;
            }
        }

        static::assertSame([], $leftClassNames);
    }

    public function testTheServicesOfTheVoiceFolderArePublic(): void
    {
        static::assertContains('DDD\\Domain\\AI\\Services\\Voice\\', AgentsModule::getPublicServiceNamespaces());
    }

    public function testTheVoiceConfigLiesInTheModulesConfigRootThatConfigReads(): void
    {
        static::assertFileExists(AgentsModule::getConfigPath() . '/AI/agentVoice.php');
        static::assertSame(realpath(static::getPackageDirectory() . '/config/app'), realpath((string)AgentsModule::getConfigPath()));
        static::assertContains(realpath((string)AgentsModule::getConfigPath()), array_map('realpath', Config::getConfigRootDirectories()));
    }

    #[DataProvider('provideVoiceServices')]
    public function testAVoiceServiceResolvesFromTheContainer(string $serviceClassName): void
    {
        static::assertInstanceOf($serviceClassName, DDDService::instance()->getService($serviceClassName));
    }

    /** @return array<string, array{string}> */
    public static function provideVoiceServices(): array
    {
        return [
            'the call store port' => [AgentVoiceCallStoreService::class],
            'the binding entity' => [AgentVoiceCallEntityStoreService::class],
            'the table condition provider' => [AgentVoiceFeatureTableConditionProviderService::class],
        ];
    }

    /** @return string[] The owned namespaces of the module the name lies below */
    protected static function findOwnedNamespacesOf(string $className): array
    {
        return array_values(array_filter(
            AgentsModule::getOwnedNamespaces(),
            static fn (string $ownedNamespace): bool => str_starts_with($className, $ownedNamespace)
        ));
    }

    /** @return string[] The classes, interfaces, traits and enums of `src/` below a `Voice\` folder */
    protected static function findVoiceClassNamesOfTheSource(): array
    {
        return array_values(array_filter(
            (new ModuleNamespaceChecker())->findDeclaredClassNames(static::getSourceDirectory()),
            static fn (string $className): bool => str_contains($className, '\\Voice\\')
        ));
    }

    protected static function getSourceDirectory(): string
    {
        return static::getPackageDirectory() . '/src';
    }

    protected static function getPackageDirectory(): string
    {
        return dirname(__DIR__, 4);
    }
}
