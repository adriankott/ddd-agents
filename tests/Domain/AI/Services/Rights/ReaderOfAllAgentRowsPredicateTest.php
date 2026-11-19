<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Rights;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpace;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversations;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\Embeddings\DBAIConversationMessageEmbedding;
use DDD\Domain\AI\Repo\DB\Conversations\TurnEvents\DBAIConversationTurnEventSnapshot;
use DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\AgentEvalSuiteCases\DBAgentEvalSuiteCase;
use DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\DBAgentEvalSuiteRun;
use DDD\Domain\AI\Repo\DB\Mandates\DBAgentMandate;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Entities\Roles\Roles;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * The module's own "reads every row" predicate ({@see AgentAccountRightsService::isAccountReaderOfAllAgentRows()}):
 * by default the framework's admin with access to all resources reads every row of the agent's tables; an application
 * that narrows the predicate takes that away from every rule at once — spaces, mandates, message embeddings,
 * evaluation rows, turn-event snapshots, the conversation tables and the use of a space.
 */
class ReaderOfAllAgentRowsPredicateTest extends IntegrationTestCase
{
    protected bool $rightsRestrictionsBeforeTest = true;

    protected function setUp(): void
    {
        $this->rightsRestrictionsBeforeTest = DBEntity::getApplyRightsRestrictions();
        DBEntity::setApplyRightsRestrictions(true);
    }

    protected function onTearDown(): void
    {
        DBEntity::setApplyRightsRestrictions($this->rightsRestrictionsBeforeTest);
    }

    public function testByDefaultTheAdminReadsEverySpaceMandateEmbeddingAndEvaluationRow(): void
    {
        static::authenticate(static::buildAccount(7, true));
        $agentAccountRightsService = new AgentAccountRightsService();
        $admin = static::buildAccount(7, true);
        self::assertTrue($agentAccountRightsService->isAccountReaderOfAllAgentRows($admin));
        self::assertTrue($agentAccountRightsService->isAccountReaderOfAllAgentSpaces($admin));
        self::assertTrue($agentAccountRightsService->isAccountReaderOfAllAgentMandates($admin));
        self::assertTrue($agentAccountRightsService->isAccountReaderOfAllMessageEmbeddings($admin));
        self::assertTrue($agentAccountRightsService->isAccountReaderOfAllEvaluationRows($admin));
        self::assertTrue($agentAccountRightsService->isAccountReaderOfAllTurnEventSnapshots($admin));
        self::assertFalse($agentAccountRightsService->isAccountReaderOfAllAgentRows(static::buildAccount(8, false)));

        foreach ([DBAgentSpace::class, DBAgentMandate::class, DBAgentEvalSuiteRun::class, DBAgentEvalSuiteCase::class] as $repositoryClass) {
            $queryBuilder = $repositoryClass::createQueryBuilder(true);
            $dqlBefore = $queryBuilder->getDQL();
            self::assertFalse($repositoryClass::applyReadRightsQuery($queryBuilder), $repositoryClass . ' adds no condition for the admin');
            self::assertSame($dqlBefore, $queryBuilder->getDQL());
        }
        foreach ([DBAIConversationMessageEmbedding::class, DBAIConversationTurnEventSnapshot::class, DBAIConversations::class] as $repositoryClass) {
            $queryBuilder = $repositoryClass::createQueryBuilder(true);
            $dqlBefore = $queryBuilder->getDQL();
            $repositoryClass === DBAIConversations::class
                ? (new AgentAccountRightsService())->applyAccountReadRights($queryBuilder, $repositoryClass::getBaseModelAlias())
                : $repositoryClass::applyReadRightsQuery($queryBuilder);
            self::assertSame($dqlBefore, $queryBuilder->getDQL(), $repositoryClass . ' adds no condition for the admin');
        }
    }

    public function testANarrowedPredicateConfinesTheAdminToItsOwnRows(): void
    {
        $this->replaceService(AgentAccountRightsService::class, new AgentAccountRightsServiceWithoutReadersOfAllRowsDouble());
        static::authenticate(static::buildAccount(7, true));

        foreach ([DBAgentSpace::class => ':agentSpaceRightsAuthAccountId', DBAgentMandate::class => ':mandateRightsAuthAccountId'] as $repositoryClass => $parameterName) {
            $queryBuilder = $repositoryClass::createQueryBuilder(true);
            self::assertTrue($repositoryClass::applyReadRightsQuery($queryBuilder));
            self::assertStringContainsString($repositoryClass::getBaseModelAlias() . '.accountId = ' . $parameterName, $queryBuilder->getDQL());
        }
        foreach ([DBAgentEvalSuiteRun::class, DBAgentEvalSuiteCase::class, DBAIConversationTurnEventSnapshot::class] as $repositoryClass) {
            $queryBuilder = $repositoryClass::createQueryBuilder(true);
            self::assertTrue($repositoryClass::applyReadRightsQuery($queryBuilder));
            self::assertStringContainsString($repositoryClass::getBaseModelAlias() . '.id is null', $queryBuilder->getDQL(), $repositoryClass . ' is hidden');
        }
        $queryBuilder = DBAIConversationMessageEmbedding::createQueryBuilder(true);
        self::assertTrue(DBAIConversationMessageEmbedding::applyReadRightsQuery($queryBuilder));
        self::assertStringContainsString('_aiConversationMessage_rights_aiConversation_rights.accountId = :rights_user_id', $queryBuilder->getDQL());
        $queryBuilder = DBAIConversations::createQueryBuilder(true);
        /** @var AgentAccountRightsService $agentAccountRightsService */
        $agentAccountRightsService = DDDService::instance()->getService(AgentAccountRightsService::class);
        self::assertTrue($agentAccountRightsService->applyAccountReadRights($queryBuilder, DBAIConversations::getBaseModelAlias()));
        self::assertStringContainsString(DBAIConversations::getBaseModelAlias() . '.accountId = :rights_user_id', $queryBuilder->getDQL());
    }

    public function testANarrowedPredicateRefusesTheAdminASpaceOfAnotherAccount(): void
    {
        $this->replaceService(AgentAccountRightsService::class, new AgentAccountRightsServiceWithoutReadersOfAllRowsDouble());
        /** @var AgentSpace $agentSpace */
        $agentSpace = (new ReflectionClass(AgentSpace::class))->newInstanceWithoutConstructor();
        $agentSpace->accountId = 8;
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $this->expectException(ForbiddenException::class);
        $agentSpacesService->requireAgentSpaceUsableByAccount($agentSpace, static::buildAccount(7, true));
    }

    /** Sets the authenticated account directly (an application's `setAccount()` may expect its own account class). */
    protected static function authenticate(?Account $account): void
    {
        (new ReflectionProperty(AuthService::class, 'account'))->setValue(null, $account);
    }

    /** A framework account with the LOGIN role, plus ADMIN when `$isAdmin`. */
    protected static function buildAccount(int $accountId, bool $isAdmin): Account
    {
        /** @var Account $account */
        $account = (new ReflectionClass(Account::class))->newInstanceWithoutConstructor();
        $account->id = $accountId;
        /** @var Roles $roles */
        $roles = (new ReflectionClass(Roles::class))->newInstanceWithoutConstructor();
        $roleElements = [];
        $roleElementsByUniqueKey = [];
        foreach ($isAdmin ? [Role::LOGIN, Role::ADMIN] : [Role::LOGIN] as $roleName) {
            /** @var Role $role */
            $role = (new ReflectionClass(Role::class))->newInstanceWithoutConstructor();
            $role->name = $roleName;
            $role->isAdminRole = $roleName !== Role::LOGIN;
            $roleElements[] = $role;
            $roleElementsByUniqueKey[$roleName] = $role;
        }
        static::setProperty($roles, 'elements', $roleElements);
        static::setProperty($roles, 'elementsByUniqueKey', $roleElementsByUniqueKey);
        static::setProperty($roles, 'elementCount', count($roleElements));
        $account->roles = $roles;
        return $account;
    }

    protected static function setProperty(object $object, string $propertyName, mixed $value): void
    {
        $reflectionClass = new ReflectionClass($object);
        while (!$reflectionClass->hasProperty($propertyName)) {
            $reflectionClass = $reflectionClass->getParentClass();
        }
        $reflectionClass->getProperty($propertyName)->setValue($object, $value);
    }
}
