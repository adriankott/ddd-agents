<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Infrastructure\Services\AuthService;

/**
 * What a recovery command did, in order, each entry with the account authenticated at that moment (`<entry>@<account id>`,
 * `@-` without one) — shared by a probe command and the service doubles of one run. Beside each entry it keeps whether
 * the entity rights restrictions applied at that moment.
 */
class RecoveryPassLog
{
    /** @var string[] */
    public array $entries = [];

    /** @var bool[] Whether the entity rights restrictions applied, one per entry */
    public array $rightsAppliedAtEntries = [];

    public function record(string $entry): void
    {
        $authenticatedAccount = AuthService::instance()->getAccount();
        $this->entries[] = $entry . '@' . ($authenticatedAccount !== null && isset($authenticatedAccount->id) ? $authenticatedAccount->id : '-');
        $this->rightsAppliedAtEntries[] = DBEntity::getApplyRightsRestrictions();
    }
}
