<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization;

use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;

/**
 * A purpose that inherits another purpose's seed and reshapes it: its built seed drops the widget gates of the
 * configured items (a purpose whose owner sees no widgets), or every item when the test asks it to.
 */
class PurposeDroppingTheWidgetGatesOfItsSeed extends AIConversationPurpose
{
    public bool $dropsEveryItem = false;

    public function buildSeedTaskPlanDefinition(): array
    {
        if ($this->dropsEveryItem) {
            return [];
        }
        $builtSeedItems = [];
        foreach ($this->seedTaskPlanItems as $seedItem) {
            unset($seedItem['doneRequiresWidget']);
            $builtSeedItems[] = $seedItem;
        }
        return $builtSeedItems;
    }
}
