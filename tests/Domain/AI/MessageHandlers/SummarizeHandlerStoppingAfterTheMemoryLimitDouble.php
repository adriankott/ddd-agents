<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\MessageHandlers;

use DDD\Domain\AI\MessageHandlers\AIConversationSummarizeHandler;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;
use LogicException;

/**
 * The summarize handler, stopped at its first step after the time and memory limits (the auth context): the job's
 * limit handling runs for real, nothing after it (no database, no model call).
 */
class SummarizeHandlerStoppingAfterTheMemoryLimitDouble extends AIConversationSummarizeHandler
{
    public const string STOP_MESSAGE = 'stopped after the memory limit';

    protected function setAuthAccountFromMessage(AppMessage $appMessage): void
    {
        throw new LogicException(self::STOP_MESSAGE);
    }
}
