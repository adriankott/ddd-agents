<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Admission;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Services\AccountsService;

/** The account service answering from {@see $accountsById}, recording each id and whether the rights applied. */
class FixedAccountsService extends AccountsService
{
    /** @var array<int, Account> */
    public array $accountsById = [];

    /** @var array<int, array{accountId: int, rightsApplied: bool}> */
    public array $foundAccounts = [];

    public function find(int|string|null $entityId, bool $useEntityRegistrCache = true): ?Account
    {
        $this->foundAccounts[] = ['accountId' => (int)$entityId, 'rightsApplied' => DBEntity::getApplyRightsRestrictions()];

        return $this->accountsById[(int)$entityId] ?? null;
    }
}
