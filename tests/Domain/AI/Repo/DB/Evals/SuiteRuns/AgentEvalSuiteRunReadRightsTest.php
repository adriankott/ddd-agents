<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\DB\Evals\SuiteRuns;

use DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\AgentEvalSuiteCases\DBAgentEvalSuiteCase;
use DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\DBAgentEvalSuiteRun;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Entities\Roles\Roles;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The read rights of the stored evaluation runs and their cases, read off the query they build (no database is
 * asked): an admin with access to all resources reads every row — no condition, and the method says so —, every other
 * account and a request without an account read none (`id is null`, and the method says a condition was added, so an
 * expand of the rows from elsewhere keeps it). With the rights lifted nothing is added.
 */
class AgentEvalSuiteRunReadRightsTest extends UnitTestCase
{
    /** @var string[] The repositories of the two tables */
    protected const array REPOSITORY_CLASSES = [DBAgentEvalSuiteRun::class, DBAgentEvalSuiteCase::class];

    protected ?Account $accountBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBefore = AuthService::instance()->getAccount();
    }

    protected function tearDown(): void
    {
        AuthService::instance()->setAccount($this->accountBefore);
        parent::tearDown();
    }

    public function testAnAdminWithAccessToAllResourcesReadsEveryRow(): void
    {
        foreach ([Role::SUPERADMIN, Role::ADMIN] as $roleName) {
            $this->authenticateWithRole($roleName);
            foreach (self::REPOSITORY_CLASSES as $repositoryClass) {
                [$conditionAdded, $whereClause] = $this->applyReadRights($repositoryClass);
                self::assertFalse($conditionAdded, $repositoryClass . ' / ' . $roleName);
                self::assertSame('', $whereClause, $repositoryClass . ' / ' . $roleName);
            }
        }
    }

    public function testEveryOtherAccountAndNoAccountReadNothing(): void
    {
        foreach (['USER', null] as $roleName) {
            if ($roleName === null) {
                $noAccount = null;
                AuthService::instance()->setAccount($noAccount);
            } else {
                $this->authenticateWithRole($roleName);
            }
            foreach (self::REPOSITORY_CLASSES as $repositoryClass) {
                [$conditionAdded, $whereClause] = $this->applyReadRights($repositoryClass);
                self::assertTrue($conditionAdded, $repositoryClass . ' / ' . ($roleName ?? 'no account'));
                self::assertSame($repositoryClass::getBaseModelAlias() . '.id is null', $whereClause, $repositoryClass);
            }
        }
    }

    public function testLiftedRightsAddNothing(): void
    {
        $this->authenticateWithRole('USER');
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            foreach (self::REPOSITORY_CLASSES as $repositoryClass) {
                [$conditionAdded, $whereClause] = $this->applyReadRights($repositoryClass);
                self::assertFalse($conditionAdded, $repositoryClass);
                self::assertSame('', $whereClause, $repositoryClass);
            }
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
    }

    /**
     * @param class-string<DBEntity> $repositoryClass
     * @return array{0: bool, 1: string} whether the method reports a condition, and the WHERE part of the query
     */
    protected function applyReadRights(string $repositoryClass): array
    {
        $queryBuilder = $repositoryClass::createQueryBuilder(true);
        $conditionAdded = $repositoryClass::applyReadRightsQuery($queryBuilder);
        $whereClause = $queryBuilder->getDQLPart('where');
        return [$conditionAdded, $whereClause === null ? '' : (string)$whereClause];
    }

    protected function authenticateWithRole(string $roleName): void
    {
        $role = new Role();
        $role->setName($roleName);
        $roles = new Roles();
        $roles->add($role);
        $account = new Account();
        $account->id = 11;
        $account->roles = $roles;
        AuthService::instance()->setAccount($account);
    }
}
