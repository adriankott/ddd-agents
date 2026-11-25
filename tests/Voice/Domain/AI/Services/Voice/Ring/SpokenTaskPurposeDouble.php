<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;

/** A purpose made for calls: it declares {@see $spokenCallTask}. */
class SpokenTaskPurposeDouble extends AIConversationPurpose
{
    public ?string $spokenCallTask = null;

    public function getSpokenCallTask(): ?string
    {
        return $this->spokenCallTask;
    }
}
