<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService;
use DDD\Domain\Common\Entities\Accounts\Account;

/**
 * An application's call start that names a further ring target after the open clients, but (yet) overrides neither
 * its reachability nor its ring: what the base answers for a target it does not know.
 */
class FurtherRingTargetAgentVoiceCallStartsServiceDouble extends AgentVoiceCallStartsService
{
    public const string FURTHER_RING_TARGET = 'pager';

    /** @return string[] */
    public function callFindReachableRingTargetNames(Account $ownerAccount, ?AIConversation $ringConversation): array
    {
        return $this->findReachableRingTargetNames($ownerAccount, $ringConversation);
    }

    /** @return string[] */
    protected function getRingTargetNames(): array
    {
        return [static::RING_TARGET_OPEN_CLIENTS, static::FURTHER_RING_TARGET];
    }
}
