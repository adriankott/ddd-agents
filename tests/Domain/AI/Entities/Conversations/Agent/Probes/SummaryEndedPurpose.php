<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Probes;

use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;

/** A purpose that a `Summary` widget ends, without a purpose file */
class SummaryEndedPurpose extends AIConversationPurpose
{
    public function getWidgetTypesThatEndThisPurpose(): array
    {
        return ['Summary'];
    }
}
