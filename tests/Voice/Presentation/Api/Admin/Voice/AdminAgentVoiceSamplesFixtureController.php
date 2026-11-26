<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Admin\Voice;

use DDD\Domain\AI\Services\Voice\Samples\AgentVoiceSamplesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Presentation\Api\Admin\Voice\AdminAgentVoiceSamplesTrait;

/**
 * An application's admin controller that mounts the sample endpoint: the samples service is the double the test sets
 * (the trait's override point). The access hook records the accounts it is asked for and refuses with `admin_refused`
 * while {@see $accessRefused} is set.
 */
class AdminAgentVoiceSamplesFixtureController
{
    use AdminAgentVoiceSamplesTrait;

    public ?AgentVoiceSamplesService $agentVoiceSamplesService = null;

    public bool $accessRefused = false;

    /** @var Account[] */
    public array $accountsAskedForAccess = [];

    protected function throwForbiddenUnlessVoiceSamplesAccessAllowed(Account $account): void
    {
        $this->accountsAskedForAccess[] = $account;
        if ($this->accessRefused) {
            throw new ForbiddenException('admin_refused');
        }
    }

    protected function getVoiceSamplesServiceOfAdminEndpoints(): AgentVoiceSamplesService
    {
        return $this->agentVoiceSamplesService;
    }
}
