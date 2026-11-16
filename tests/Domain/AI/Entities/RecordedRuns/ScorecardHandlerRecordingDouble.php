<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\RecordedRuns;

use DDD\Domain\AI\MessageHandlers\AIConversationScorecardHandler;
use DDD\Domain\AI\MessageHandlers\AIConversationScorecardMessage;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;

/**
 * The scorecard handler as a consumed job meets it in a test: it notes the conversation of each message it handles and
 * leaves another account authenticated behind, as the fresh state of a worker job does. Reads no database.
 */
class ScorecardHandlerRecordingDouble extends AIConversationScorecardHandler
{
    public const int ACCOUNT_LEFT_BEHIND_ID = 99;

    /** @var int[] The conversations of the handled messages, in order */
    public array $handledConversationIds = [];

    public function __invoke(AIConversationScorecardMessage $message): void
    {
        $this->handledConversationIds[] = $message->aiConversationId;
        $accountLeftBehind = Account::newInstance();
        $accountLeftBehind->id = self::ACCOUNT_LEFT_BEHIND_ID;
        AuthService::instance()->setAccount($accountLeftBehind);
    }
}
