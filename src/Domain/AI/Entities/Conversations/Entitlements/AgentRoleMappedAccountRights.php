<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Entitlements;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Libs\Config;
use Throwable;

/**
 * The default rights of an account: a right is held when the account has one of the roles the config `AI.accountRights`
 * maps it to ({@see AgentAccountRights}); {@see self::EVERY_ACCOUNT} grants it to every account the agent acts for. An
 * entry `['right' => '<application right name>']` ({@see self::DELEGATED_RIGHT_KEY}) holds the right when the
 * application's rights object holds that right of its own ({@see AgentComposedAccountRights}); without an application
 * object the named right is looked up in the same mapping (one level). A right the mapping does not name is not held.
 * An application with its own rights model implements {@see AgentAccountRightsInterface} itself and returns it from its
 * `AIConversationEntitlementsService`; the package composes it with this mapping, so package rights the application
 * does not answer still follow the mapping.
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

    /** @var string The key of a mapping entry that holds the right by a right of the application (`['right' => 'manageTasks']`) */
    public const string DELEGATED_RIGHT_KEY = 'right';

    /** @var array<string, string[]> right name => role names (the mapping these rights answer by) */
    protected array $roleNamesByRightName = [];

    /** @var string[] right name => the application's right name that holds it (entries with {@see DELEGATED_RIGHT_KEY}) */
    protected array $delegatedRightNameByRightName = [];

    /** @var string[]|null The role names of the account; null until they are needed */
    protected ?array $roleNames = null;

    protected ?Account $account = null;

    /** The rights of `$account` under the configured mapping */
    public static function forAccount(Account $account): static
    {
        $agentRoleMappedAccountRights = new static();
        $agentRoleMappedAccountRights->account = $account;
        $mappingEntries = static::readConfiguredMappingEntries();
        $agentRoleMappedAccountRights->roleNamesByRightName = static::parseRoleNamesByRightName($mappingEntries);
        $agentRoleMappedAccountRights->delegatedRightNameByRightName = static::parseDelegatedRightNameByRightName($mappingEntries);
        return $agentRoleMappedAccountRights;
    }

    /**
     * The rights of an account with `$roleNames` under `$roleNamesByRightName` (right name => role names).
     *
     * A mapping entry may also be a delegation (`['right' => '<application right name>']`).
     *
     * @param string[] $roleNames
     * @param array<string, mixed> $roleNamesByRightName
     */
    public static function forRoleNames(array $roleNames, array $roleNamesByRightName): static
    {
        $agentRoleMappedAccountRights = new static();
        $agentRoleMappedAccountRights->roleNames = array_values(array_unique($roleNames));
        $agentRoleMappedAccountRights->roleNamesByRightName = static::parseRoleNamesByRightName($roleNamesByRightName);
        $agentRoleMappedAccountRights->delegatedRightNameByRightName = static::parseDelegatedRightNameByRightName($roleNamesByRightName);
        return $agentRoleMappedAccountRights;
    }

    /**
     * The rights of an account with `$roleNames` under the configured mapping (its delegations included); the account
     * itself is not read. For a caller that knows the roles, and for an account-less check ([] = only `'*'` holds).
     *
     * @param string[] $roleNames
     */
    public static function forRoleNamesUnderConfiguredMapping(array $roleNames): static
    {
        return static::forRoleNames($roleNames, static::readConfiguredMappingEntries());
    }

    /**
     * The delegations of the configured mapping: right name => the application's right name (`['right' => …]`).
     *
     * @return string[]
     */
    public static function readConfiguredDelegations(): array
    {
        return static::parseDelegatedRightNameByRightName(static::readConfiguredMappingEntries());
    }

    /**
     * The raw entries of the configured mapping (right name => role list or delegation); [] for a missing or broken
     * config.
     *
     * @return array<string, mixed>
     */
    public static function readConfiguredMappingEntries(): array
    {
        $configuredMapping = Config::get(static::CONFIG_PATH);
        return is_array($configuredMapping) ? $configuredMapping : [];
    }

    /**
     * The role lists of mapping entries: right name => role names (the list entries; a delegation key is no role).
     *
     * @param array<array-key, mixed> $mappingEntries
     * @return array<string, string[]>
     */
    public static function parseRoleNamesByRightName(array $mappingEntries): array
    {
        $roleNamesByRightName = [];
        foreach ($mappingEntries as $rightName => $mappingEntry) {
            if (!is_string($rightName) || !is_array($mappingEntry)) {
                continue;
            }
            $listedRoleNames = [];
            foreach ($mappingEntry as $roleNameKey => $roleName) {
                if (is_int($roleNameKey) && is_string($roleName)) {
                    $listedRoleNames[] = $roleName;
                }
            }
            $roleNamesByRightName[$rightName] = $listedRoleNames;
        }
        return $roleNamesByRightName;
    }

    /**
     * The delegations of mapping entries: right name => the application's right name (`['right' => …]`).
     *
     * @param array<array-key, mixed> $mappingEntries
     * @return string[]
     */
    public static function parseDelegatedRightNameByRightName(array $mappingEntries): array
    {
        $delegatedRightNameByRightName = [];
        foreach ($mappingEntries as $rightName => $mappingEntry) {
            $delegatedRightName = is_array($mappingEntry) ? ($mappingEntry[static::DELEGATED_RIGHT_KEY] ?? null) : null;
            if (is_string($rightName) && is_string($delegatedRightName) && trim($delegatedRightName) !== '') {
                $delegatedRightNameByRightName[$rightName] = trim($delegatedRightName);
            }
        }
        return $delegatedRightNameByRightName;
    }

    /**
     * Whether the mapping names `$rightName` at all (roles, every account or a delegation). A right it does not name is
     * not held by the mapping.
     */
    public function namesRight(string $rightName): bool
    {
        return isset($this->roleNamesByRightName[$rightName]) || isset($this->delegatedRightNameByRightName[$rightName]);
    }

    /** The application's right name `$rightName` is delegated to (`['right' => …]`), or null */
    public function findDelegatedRightName(string $rightName): ?string
    {
        return $this->delegatedRightNameByRightName[$rightName] ?? null;
    }

    /**
     * The role names the mapping gives `$rightName` (`'*'` included); [] when the mapping gives it to no role.
     *
     * @return string[]
     */
    public function getRoleNamesOfRight(string $rightName): array
    {
        return $this->roleNamesByRightName[$rightName] ?? [];
    }

    /**
     * The right names the mapping names, in its order.
     *
     * @return string[]
     */
    public function getMappedRightNames(): array
    {
        return array_values(array_unique([...array_keys($this->roleNamesByRightName), ...array_keys($this->delegatedRightNameByRightName)]));
    }

    /** @return array<string, string[]> The role lists of the configured mapping; a missing or broken config grants nothing */
    public static function readConfiguredMapping(): array
    {
        return static::parseRoleNamesByRightName(static::readConfiguredMappingEntries());
    }

    /**
     * Whether the mapping holds `$rightName` for the account: by one of its roles, by `'*'`, or by its delegation —
     * asked of `$applicationAccountRights` when given, else looked up in this mapping by the roles (one level).
     */
    public function holdsRight(string $rightName, ?AgentAccountRightsInterface $applicationAccountRights = null): bool
    {
        if ($this->holdsRightByRoles($rightName)) {
            return true;
        }
        $delegatedRightName = $this->findDelegatedRightName($rightName);
        if ($delegatedRightName === null) {
            return false;
        }
        if ($applicationAccountRights !== null) {
            return $applicationAccountRights->getMissingRightNames([$delegatedRightName]) === [];
        }
        return $delegatedRightName !== $rightName && $this->holdsRightByRoles($delegatedRightName);
    }

    /** Whether one of the account's roles (or `'*'`) holds `$rightName` under the mapping */
    public function holdsRightByRoles(string $rightName): bool
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
