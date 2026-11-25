<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Agents\Voice;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Presentation\Base\Dtos\RequestDto;

/**
 * The controller of an application in which an agency user presses "call me" for the owner it acts for: the ring
 * account is the owner of the agent space (the space's `accountId`), and the signed-in account where the request names
 * no space. A real application checks in this method that the signed-in user acts for the space before it names the
 * owner (the routes add no such check); this fixture leaves the check out: its tests are about what the routes do with
 * the hook's answer.
 * Every question to the hook is written into {@see $ringAccountQuestions} as `<request DTO class>:<space id or none>`.
 */
class SpaceOwnerRungAgentVoiceSessionsFixtureController extends AgentVoiceSessionsFixtureController
{
    /** @var string[] The questions the hook was asked, in order */
    public array $ringAccountQuestions = [];

    protected function findRingAccountOfCallRequest(RequestDto $requestDto, ?AgentSpace $agentSpace): Account
    {
        $this->ringAccountQuestions[] = $requestDto::class . ':' . ($agentSpace?->id ?? 'none');
        if ($agentSpace?->accountId === null) {
            return parent::findRingAccountOfCallRequest($requestDto, $agentSpace);
        }
        $ownerAccount = new Account();
        $ownerAccount->id = $agentSpace->accountId;

        return $ownerAccount;
    }
}
