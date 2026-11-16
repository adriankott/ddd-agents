<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\RecordedRuns;

use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunScope;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunScopeColumns;
use DDD\Domain\AI\Services\Eval\AgentRecordedRunsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The scope of a recorded run: the created-id read is scoped by the run's tree once the root is set, then by the
 * columns of the run's scopes in the order of the scope columns; the tables read through a shared scope are listed.
 */
class AgentRecordedRunScopeTest extends UnitTestCase
{
    /** An application's scope columns: its own scope names, several columns per scope. */
    protected static function applicationScopeColumns(): AgentRecordedRunScopeColumns
    {
        return AgentRecordedRunScopeColumns::newInstance()
            ->withScope('garden', 'gardenId', 'garden_id')
            ->withScope('plot', 'plotId')
            ->withScope('account', 'accountId', 'account_id');
    }

    public function testTheMidRunReadIsScopedByTheTreeOnceTheRootIsSetThenByTheRunScope(): void
    {
        $agentRecordedRunScope = AgentRecordedRunScope::forScopeIds(['account' => 581, 'garden' => 20161723, 'plot' => null], ['account'], 640001);

        $scopeValuesByColumn = $agentRecordedRunScope->buildScopeValuesByColumn(self::applicationScopeColumns(), [640001, 640002]);

        self::assertSame(['aiConversationId', 'parentConversationId', 'rootConversationId', 'gardenId', 'garden_id', 'accountId', 'account_id'], array_keys($scopeValuesByColumn), 'the columns in the order of the scope columns; a scope without an id is left out');
        self::assertSame([640001], $scopeValuesByColumn['rootConversationId']);
        self::assertSame([640001, 640002], $scopeValuesByColumn['aiConversationId']);
        self::assertSame([20161723], $scopeValuesByColumn['gardenId']);
    }

    public function testBeforeTheRootExistsOnlyTheScopeColumnsScopeTheRead(): void
    {
        $agentRecordedRunScope = AgentRecordedRunScope::forScopeIds(['agentSpace' => 7, 'account' => 3]);
        /** @var AgentRecordedRunsService $agentRecordedRunsService */
        $agentRecordedRunsService = DDDService::instance()->getService(AgentRecordedRunsService::class);

        $databaseRowScopeColumns = $agentRecordedRunScope->buildRowScopeColumns($agentRecordedRunsService->getScopeColumnsByName(), []);

        self::assertSame(['agentSpaceId', 'accountId'], array_map(static fn ($databaseRowScopeColumn): string => $databaseRowScopeColumn->columnName, array_values($databaseRowScopeColumns->getElements())));
    }

    public function testTablesReadThroughASharedScopeAreListed(): void
    {
        $agentRecordedRunScope = AgentRecordedRunScope::forScopeIds(['garden' => 4, 'account' => 3]);
        $scopeColumnByTable = ['conversationMessages' => 'aiConversationId', 'accountNotes' => 'accountId', 'gardenBeds' => 'garden_id'];

        self::assertSame(['accountNotes' => 'accountId'], $agentRecordedRunScope->findSharedScopeTables($scopeColumnByTable, self::applicationScopeColumns()), 'the account is shared by default');
        // the run works on an existing garden: its garden-scoped tables are shared too
        $agentRecordedRunScope->sharedScopeNames = ['account', 'garden'];
        self::assertSame(['accountNotes' => 'accountId', 'gardenBeds' => 'garden_id'], $agentRecordedRunScope->findSharedScopeTables($scopeColumnByTable, self::applicationScopeColumns()));
    }

    public function testAddedScopeIdsReplaceTheIdOfTheSameName(): void
    {
        $agentRecordedRunScope = AgentRecordedRunScope::forScopeIds(['garden' => 4, 'account' => 3]);
        $agentRecordedRunScope->addScopeIds(['garden' => 5, 'rootConversation' => 501]);

        self::assertSame(['garden' => 5, 'account' => 3, 'rootConversation' => 501], $agentRecordedRunScope->scopeIdsByName);
    }

    public function testAScopeIsAddedAfterThePresentOnesAndAColumnStaysWithItsFirstScope(): void
    {
        $gardenColumns = AgentRecordedRunScopeColumns::newInstance()->withScope('garden', 'gardenId');
        $withAccount = $gardenColumns->withScope('account', 'accountId', 'gardenId');

        self::assertSame(['garden'], $gardenColumns->getScopeNames(), 'the columns the scope was added to are unchanged');
        self::assertSame(['garden', 'account'], $withAccount->getScopeNames());
        self::assertSame(['gardenId'], $withAccount->getColumnsOfScope('garden'));
        self::assertSame(['accountId'], $withAccount->getColumnsOfScope('account'));
    }

    public function testThePackagesScopeColumnsNameTheSpaceThenTheAccount(): void
    {
        /** @var AgentRecordedRunsService $agentRecordedRunsService */
        $agentRecordedRunsService = DDDService::instance()->getService(AgentRecordedRunsService::class);
        $agentRecordedRunScopeColumns = $agentRecordedRunsService->getScopeColumnsByName();

        self::assertSame(['agentSpace', 'account'], $agentRecordedRunScopeColumns->getScopeNames());
        self::assertSame(['agentSpaceId'], $agentRecordedRunScopeColumns->getColumnsOfScope('agentSpace'));
        self::assertSame(['accountId', 'agentSpaceId'], $agentRecordedRunScopeColumns->getColumnsOfScopes(['account', 'agentSpace']));
        self::assertSame([], $agentRecordedRunScopeColumns->getColumnsOfScope('unknown'));
    }
}
