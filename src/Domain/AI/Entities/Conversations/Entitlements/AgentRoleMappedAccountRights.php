<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Entitlements;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Libs\Config;
use Throwable;

/**
 * The default rights of an account: a right is held when the account has one of the roles the config `AI.accountRights`
 * maps it to ({@see AgentAccountRights}); {@see self::EVERY_ACCOUNT} grants it to every account the agent acts for. A
 * right the mapping does not name is not held. An application with its own rights model implements
 * {@see AgentAccountRightsInterface} itself and returns it from its `AIConversationEntitlementsService`.
 *
 * The account's roles are read only when a right is asked that the mapping gives to roles (not to every account); a
 * failed read holds no role.
 *
 * @phpstan-consistent-constructor
 */
class AgentRoleMappedAccountRights implements AgentAccountRightsInterface
{
    /** @var string The role list entry that grants a right to every account */
    public const string EVERY_ACCOUNT = '*';

    /** @var string The config path of the mapping (right name => role names) */
    public const string CONFIG_PATH = 'AI.accountRights';

    /** @var array<string, string[]> right name => role names (the mapping these rights answer by) */
    protected array $roleNamesByRightName = [];

    /** @var string[]|null The role names of the account; null until they are needed */
    protected ?array $roleNames = null;

    protected ?Account $account = null;

    /** The rights of `$account` under the configured mapping */
    public static function forAccount(Account $account): static
    {
        $agentRoleMappedAccountRights = new static();
        $agentRoleMappedAccountRights->account = $account;
        $agentRoleMappedAccountRights->roleNamesByRightName = static::readConfiguredMapping();
        return $agentRoleMappedAccountRights;
    }

    /**
     * The rights of an account with `$roleNames` under `$roleNamesByRightName` (right name => role names).
     *
     * @param string[] $roleNames
     * @param array<string, string[]> $roleNamesByRightName
     */
    public static function forRoleNames(array $roleNames, array $roleNamesByRightName): static
    {
        $agentRoleMappedAccountRights = new static();
        $agentRoleMappedAccountRights->roleNames = array_values(array_unique($roleNames));
        $agentRoleMappedAccountRights->roleNamesByRightName = $roleNamesByRightName;
        return $agentRoleMappedAccountRights;
    }

    /** @return array<string, string[]> The configured mapping; a missing or broken config grants nothing */
    public static function readConfiguredMapping(): array
    {
        $configuredMapping = Config::get(static::CONFIG_PATH);
        if (!is_array($configuredMapping)) {
            return [];
        }
        $roleNamesByRightName = [];
        foreach ($configuredMapping as $rightName => $roleNames) {
            if (is_string($rightName) && is_array($roleNames)) {
                $roleNamesByRightName[$rightName] = array_values(array_filter($roleNames, 'is_string'));
            }
        }
        return $roleNamesByRightName;
    }

    public function holdsRight(string $rightName): bool
    {
        $grantedRoleNames = $this->roleNamesByRightName[$rightName] ?? [];
        if (in_array(static::EVERY_ACCOUNT, $grantedRoleNames, true)) {
            return true;
        }
        return $grantedRoleNames !== [] && array_intersect($grantedRoleNames, $this->getRoleNames()) !== [];
    }

    public function getMissingRightNames(array $rightNames): array
    {
        $missingRightNames = [];
        foreach ($rightNames as $rightName) {
            if (!$this->holdsRight((string)$rightName)) {
                $missingRightNames[] = (string)$rightName;
            }
        }
        return $missingRightNames;
    }

    /** @return string[] The account's role names, read once */
    protected function getRoleNames(): array
    {
        if ($this->roleNames !== null) {
            return $this->roleNames;
        }
        $roleNames = [];
        try {
            // a read, not isset(): the roles may be a lazy relation that only a read loads
            $roles = $this->account !== null ? ($this->account->roles ?? null) : null;
            foreach ($roles !== null ? $roles->getElements() : [] as $role) {
                if (isset($role->name)) {
                    $roleNames[] = $role->name;
                }
            }
        } catch (Throwable) {
            $roleNames = [];
        }
        $this->roleNames = array_values(array_unique($roleNames));
        return $this->roleNames;
    }
}
