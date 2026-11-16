<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Eval\AIConversationEvalPurgeService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Symfony\Commands\Agents\AgentEvalConversationsPurgeCommand;
use DDD\Tests\Agents\Domain\AI\Services\Eval\AIConversationEvalPurgeServiceRecordingDouble;

/**
 * The purge command on a scripted purge service ({@see AIConversationEvalPurgeServiceRecordingDouble}) and without a
 * default console account (no database read).
 */
class AgentEvalConversationsPurgeProbeCommand extends AgentEvalConversationsPurgeCommand
{
    public function __construct(public AIConversationEvalPurgeServiceRecordingDouble $scriptedPurgeService)
    {
        parent::__construct();
    }

    protected function findDefaultAccountForConsoleWork(): ?Account
    {
        return null;
    }

    protected function getEvalPurgeService(): AIConversationEvalPurgeService
    {
        return $this->scriptedPurgeService;
    }
}
