<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallRefusal;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\Common\Entities\Accounts\Account;

/** The admission port answering {@see $refusalCode} (null = the call may start) and recording every call start it was asked. */
class FixedRefusalAgentVoiceCallAdmissionServiceDouble extends AgentVoiceCallAdmissionService
{
    public ?string $refusalCode = null;

    /** @var array<int, array{accountId: int, agentSpace: ?AgentSpace, conversationId: ?int}> */
    public array $askedCallStarts = [];

    public function __construct()
    {
    }

    public function findRefusalOfCallStart(Account $account, ?AgentSpace $agentSpace, ?AIConversation $conversation): ?AgentVoiceCallRefusal
    {
        $this->askedCallStarts[] = [
            'accountId' => (int)$account->id,
            'agentSpace' => $agentSpace,
            'conversationId' => isset($conversation->id) ? (int)$conversation->id : null,
        ];
        return $this->refusalCode !== null ? AgentVoiceCallRefusal::forCode($this->refusalCode) : null;
    }
}
