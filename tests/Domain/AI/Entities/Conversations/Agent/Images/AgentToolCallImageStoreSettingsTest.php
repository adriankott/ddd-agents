<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Images;

use DDD\Domain\AI\Entities\Conversations\Agent\Images\AgentToolCallImageStoreSettings;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The settings of `AI.toolCallImageStore`: the shipped file, nested keys, defaults for missing and invalid values. */
class AgentToolCallImageStoreSettingsTest extends UnitTestCase
{
    public function testTheShippedConfigFileReadsAsTheDefaults(): void
    {
        $shippedNode = require dirname(__DIR__, 7) . '/config/app/AI/toolCallImageStore.php';

        self::assertEquals(new AgentToolCallImageStoreSettings(), AgentToolCallImageStoreSettings::fromConfigNode($shippedNode));
    }

    public function testNestedKeysAreRead(): void
    {
        $settings = AgentToolCallImageStoreSettings::fromConfigNode([
            'processCache' => ['enabled' => false, 'cacheGroup' => 'apc', 'maxBytes' => 1024, 'ttlSeconds' => 60],
            'sharedCache' => ['cacheGroup' => 'redis', 'ttlSeconds' => 900, 'extendTtlOnRead' => false],
            'objectStore' => ['enabled' => false, 'objectStorage' => 'toolPictures', 'keyPrefix' => 'pictures/'],
            'wireProfile' => ['maxEdgePixels' => 1568, 'maxMegapixels' => 1.15, 'format' => 'JPEG', 'quality' => 120],
            'retention' => ['enabled' => false, 'newestImageResults' => 0, 'maxPicturesOnWire' => 8],
        ]);

        self::assertFalse($settings->processCacheEnabled);
        self::assertSame(['apc', 1024, 60], [$settings->processCacheGroup, $settings->processCacheMaxBytes, $settings->processCacheTtlSeconds]);
        self::assertSame(['redis', 900, false], [$settings->sharedCacheGroup, $settings->sharedCacheTtlSeconds, $settings->sharedCacheExtendsTtlOnRead]);
        self::assertSame([false, 'toolPictures', 'pictures/'], [$settings->objectStoreEnabled, $settings->objectStorageName, $settings->objectKeyPrefix]);
        self::assertSame([1568, 1_150_000, 'jpeg', 100], [$settings->wireMaxEdgePixels, $settings->getWireMaxPixels(), $settings->wireFormat, $settings->wireQuality]);
        self::assertSame('image/jpeg', $settings->getWireMimeType());
        self::assertSame([false, 0, 8], [$settings->retentionEnabled, $settings->retentionNewestImageResults, $settings->retentionMaxPicturesOnWire]);
    }

    public function testInvalidValuesKeepTheirDefaults(): void
    {
        $settings = AgentToolCallImageStoreSettings::fromConfigNode([
            'enabled' => 'yes',
            'sharedCache' => ['ttlSeconds' => -5, 'cacheGroup' => '  '],
            'wireProfile' => ['format' => 'png', 'maxMegapixels' => 'many'],
            'retention' => ['newestImageResults' => -1],
        ]);

        self::assertTrue($settings->enabled);
        self::assertSame(1800, $settings->sharedCacheTtlSeconds);
        self::assertNull($settings->sharedCacheGroup);
        self::assertSame('webp', $settings->wireFormat);
        self::assertSame(3.75, $settings->wireMaxMegapixels);
        self::assertSame(4, $settings->retentionNewestImageResults);
        self::assertEquals(new AgentToolCallImageStoreSettings(), AgentToolCallImageStoreSettings::fromConfigNode(null));
    }
}
