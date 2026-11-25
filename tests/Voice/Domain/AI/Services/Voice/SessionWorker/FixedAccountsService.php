<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Services\AccountsService;

/**
 * The accounts as `Account::getService()` reads them: the accounts the test hands in, by id; every read is kept. The
 * session worker reads the billed account this way (it has no method of its own for it), and so does the framework's
 * `AuthService::setAccountId()`.
 */
class FixedAccountsService extends AccountsService
{
    /** @var array<int, Account> */
    public array $accountsById = [];

    /** @var array<int, int|string|null> */
    public array $foundAccountIds = [];

    public function find(int|string|null $entityId, bool $useEntityRegistrCache = true): ?Account
    {
        $this->foundAccountIds[] = $entityId;

        return $this->accountsById[(int)$entityId] ?? null;
    }

    public static function buildAccount(int $accountId): Account
    {
        $account = new Account();
        $account->id = $accountId;

        return $account;
    }
}
