<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallReachableChannels;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use RuntimeException;

/**
 * The call start as the channel provider asks it: each reading of what reaches a conversation's owner is counted and
 * answered with {@see $reading} (or throws with {@see $readingFailureMessage}). The account lookup of the package's
 * own reading answers from {@see $accountsById}, for the test of that reading itself.
 */
class ReadingAgentVoiceCallStartsServiceDouble extends AgentVoiceCallStartsService
{
    public ?AgentVoiceCallReachableChannels $reading = null;

    public ?string $readingFailureMessage = null;

    /** @var AIConversation[] The conversations a reading was made for, in order */
    public array $readConversations = [];

    public bool $answersReadingsItself = true;

    /** @var array<int, Account> */
    public array $accountsById = [];

    /** @var int[] Every account id the reading looked up */
    public array $lookedUpAccountIds = [];

    public function findReachableChannelsForConversation(AIConversation $conversation): ?AgentVoiceCallReachableChannels
    {
        if (!$this->answersReadingsItself) {
            return parent::findReachableChannelsForConversation($conversation);
        }
        $this->readConversations[] = $conversation;
        if ($this->readingFailureMessage !== null) {
            throw new RuntimeException($this->readingFailureMessage);
        }
        return $this->reading;
    }

    protected function findAccount(int $accountId): ?Account
    {
        $this->lookedUpAccountIds[] = $accountId;
        return $this->accountsById[$accountId] ?? null;
    }
}
