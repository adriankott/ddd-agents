<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Services\AccountsService;

/**
 * An accounts service that knows one account and reads nothing from a database.
 */
class AccountsServiceWithOneAccountDouble extends AccountsService
{
    public const int KNOWN_ACCOUNT_ID = 3;

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Account
    {
        if ((int)$entityId !== self::KNOWN_ACCOUNT_ID) {
            return null;
        }
        $account = Account::newInstance();
        $account->id = self::KNOWN_ACCOUNT_ID;
        return $account;
    }
}
