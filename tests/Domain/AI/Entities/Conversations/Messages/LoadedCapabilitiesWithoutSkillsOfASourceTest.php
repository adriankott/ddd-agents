<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The base set of an append-only conversation's first epoch leaves out the decision model's preload: the skills it
 * loaded and the tools only they carry go, everything else stays, and the set the copy was taken from is unchanged.
 */
class LoadedCapabilitiesWithoutSkillsOfASourceTest extends UnitTestCase
{
    public function testTheSkillsOfTheSourceAndTheirOwnToolsLeaveTheCopy(): void
    {
        $loadedCapabilities = LoadedCapabilities::fromCanonicalEntries([
            'Media.Images' => ['name' => 'Media.Images', 'type' => LoadedCapability::TYPE_SKILL, 'source' => LoadedCapability::SOURCE_DECISION_MODEL],
            'listImageModels' => ['name' => 'listImageModels', 'type' => LoadedCapability::TYPE_TOOL, 'parentSkillNames' => ['Media.Images']],
            'Conductor' => ['name' => 'Conductor', 'type' => LoadedCapability::TYPE_SKILL, 'source' => LoadedCapability::SOURCE_STRUCTURAL],
            'getMedia' => ['name' => 'getMedia', 'type' => LoadedCapability::TYPE_TOOL, 'parentSkillNames' => ['Media.Images', 'Conductor']],
            'search' => ['name' => 'search', 'type' => LoadedCapability::TYPE_TOOL],
        ]);

        $copy = $loadedCapabilities->withoutSkillsLoadedBySource(LoadedCapability::SOURCE_DECISION_MODEL);

        self::assertSame(['Conductor'], $copy->getLoadedSkillNames());
        self::assertSame(['getMedia', 'search'], $copy->getLoadedToolNames(), 'a tool another kept skill carries stays');
        self::assertSame(['Media.Images', 'Conductor'], $loadedCapabilities->getLoadedSkillNames(), 'the set itself is unchanged');
        self::assertSame(['Media.Images', 'Conductor'], $loadedCapabilities->withoutSkillsLoadedBySource(LoadedCapability::SOURCE_PLAN_ITEM)->getLoadedSkillNames());
    }
}
