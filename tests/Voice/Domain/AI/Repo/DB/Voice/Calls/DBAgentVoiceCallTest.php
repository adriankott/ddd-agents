<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Repo\DB\Voice\Calls;

use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCall;
use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCallModel;
use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Tests\Agents\Voice\Doubles\FixedReaderOfAllRowsAgentAccountRightsService;
use DDD\Tests\Agents\Voice\Doubles\RecordingAgentAccountRightsService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The read rights of a call: the rule of the conversation it runs on, asked of the package's rights service
 * (`applyAccountReadRights()` over the relation `aiConversation`). Pinned without a database: the query is built and
 * read back as DQL, never run.
 */
class DBAgentVoiceCallTest extends AgentVoiceIntegrationTestCase
{
    protected bool $rightsAppliedBeforeTheTest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rightsAppliedBeforeTheTest = DBEntity::getApplyRightsRestrictions();
        DBEntity::setApplyRightsRestrictions(true);
    }

    protected function onTearDown(): void
    {
        DBEntity::setApplyRightsRestrictions($this->rightsAppliedBeforeTheTest);
        parent::onTearDown();
    }

    #[DataProvider('provideRightsAnswers')]
    public function testTheRightsOfTheConversationAreAskedOverTheRelationAiConversation(bool $rightsAnswer): void
    {
        $recordingAgentAccountRightsService = new RecordingAgentAccountRightsService();
        $recordingAgentAccountRightsService->answer = $rightsAnswer;
        $this->replaceService(AgentAccountRightsService::class, $recordingAgentAccountRightsService);
        $queryBuilder = DBAgentVoiceCall::createQueryBuilder();

        static::assertSame($rightsAnswer, DBAgentVoiceCall::applyReadRightsQuery($queryBuilder));

        static::assertCount(1, $recordingAgentAccountRightsService->recordedQuestions);
        $recordedQuestion = $recordingAgentAccountRightsService->recordedQuestions[0];
        static::assertSame($queryBuilder, $recordedQuestion['queryBuilder']);
        static::assertSame(DBAgentVoiceCall::getBaseModelAlias(), $recordedQuestion['baseModelAlias']);
        static::assertSame('aiConversation', $recordedQuestion['conversationRelation']);
        static::assertSame('accountId', $recordedQuestion['accountIdProperty']);
    }

    /** @return array<string, array{bool}> */
    public static function provideRightsAnswers(): array
    {
        return ['the rights service restricts' => [true], 'the rights service answers false' => [false]];
    }

    public function testWithoutRightsRestrictionsNothingIsAsked(): void
    {
        $recordingAgentAccountRightsService = new RecordingAgentAccountRightsService();
        $this->replaceService(AgentAccountRightsService::class, $recordingAgentAccountRightsService);
        DBEntity::setApplyRightsRestrictions(false);
        $queryBuilder = DBAgentVoiceCall::createQueryBuilder();
        $dqlBefore = $queryBuilder->getDQL();

        static::assertFalse(DBAgentVoiceCall::applyReadRightsQuery($queryBuilder));
        static::assertSame([], $recordingAgentAccountRightsService->recordedQuestions);
        static::assertSame($dqlBefore, $queryBuilder->getDQL());
    }

    public function testAnAccountReadsTheCallsOfItsOwnConversations(): void
    {
        $this->replaceService(AgentAccountRightsService::class, new FixedReaderOfAllRowsAgentAccountRightsService());
        $this->signInAccount(5);
        $queryBuilder = DBAgentVoiceCall::createQueryBuilder(true);
        $alias = DBAgentVoiceCall::getBaseModelAlias();

        static::assertTrue(DBAgentVoiceCall::applyReadRightsQuery($queryBuilder));

        static::assertSame(
            'SELECT ' . $alias . ' FROM ' . DBAgentVoiceCallModel::class . ' ' . $alias
            . ' LEFT JOIN ' . $alias . '.aiConversation ' . $alias . '_aiConversation_rights'
            . ' WHERE ' . $alias . '_aiConversation_rights.accountId = :rights_user_id',
            $queryBuilder->getDQL()
        );
        static::assertSame(5, $queryBuilder->getParameter('rights_user_id')->getValue());
    }

    public function testAReaderOfEveryAgentRowReadsEveryCall(): void
    {
        $fixedReaderOfAllRowsAgentAccountRightsService = new FixedReaderOfAllRowsAgentAccountRightsService();
        $fixedReaderOfAllRowsAgentAccountRightsService->readsAllAgentRows = true;
        $this->replaceService(AgentAccountRightsService::class, $fixedReaderOfAllRowsAgentAccountRightsService);
        $this->signInAccount(5);
        $queryBuilder = DBAgentVoiceCall::createQueryBuilder(true);
        $dqlBefore = $queryBuilder->getDQL();

        static::assertTrue(DBAgentVoiceCall::applyReadRightsQuery($queryBuilder));
        static::assertSame($dqlBefore, $queryBuilder->getDQL());
    }

    public function testWithoutASignedInAccountNoCallIsRead(): void
    {
        $this->replaceService(AgentAccountRightsService::class, new FixedReaderOfAllRowsAgentAccountRightsService());
        $queryBuilder = DBAgentVoiceCall::createQueryBuilder(true);
        $alias = DBAgentVoiceCall::getBaseModelAlias();

        static::assertTrue(DBAgentVoiceCall::applyReadRightsQuery($queryBuilder));
        static::assertStringEndsWith(' WHERE ' . $alias . '.id is null', $queryBuilder->getDQL());
    }

    public function testTheModelNamesTheTableAndTheRelation(): void
    {
        static::assertSame('AgentVoiceCalls', DBAgentVoiceCallModel::getTableName());
        static::assertSame(DBAgentVoiceCallModel::class, DBAgentVoiceCall::BASE_ORM_MODEL);
        static::assertTrue(property_exists(DBAgentVoiceCallModel::class, 'aiConversation'));
    }

    protected function signInAccount(int $accountId): void
    {
        $account = Account::newInstance();
        $account->id = $accountId;
        AuthService::instance()->setAccount($account);
    }
}
