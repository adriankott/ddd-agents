<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubject;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Subjects\DBAIConversationSubjectBindingModel;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\AIConversationSubjectBindingsService;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The singleton of a purposed start with and without a subject, on both engines: the same subject reuses its open
 * conversation, another subject gets none (the start creates a new one), a start without a subject reuses only a
 * conversation without one (as before subjects existed), a closed conversation of a subject is not reused, the binding
 * is stored once per conversation, and a host without the subject table keeps the old singleton without an error.
 */
class AIConversationSubjectsDatabaseTest extends DatabaseTestCase
{
    protected const int ACCOUNT_ID = 11;

    protected const int AGENT_SPACE_ID = 21;

    protected const string PURPOSE_NAME = 'Support.AnswerTicket';

    protected const string SUBJECT_CLASS = 'App\\Domain\\Support\\Entities\\Tickets\\SupportTicket';

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        AIConversationSubjectBindingsService::forgetBindingTableCheck();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'first@example.test']);
        $this->insertAgentSpaceRow(['id' => self::AGENT_SPACE_ID, 'accountId' => self::ACCOUNT_ID]);
        // 100: the purpose without a subject; 101: ticket 1; 102 (newest): ticket 2; 103: ticket 3, closed
        $this->insertPurposedConversation(100);
        $this->insertPurposedConversation(101);
        $this->insertPurposedConversation(102);
        $this->insertPurposedConversation(103, AIConversationRunState::COMPLETED);
        $this->insertBinding(101, 1);
        $this->insertBinding(102, 2);
        $this->insertBinding(103, 3);
    }

    protected function onTearDown(): void
    {
        AIConversationSubjectBindingsService::forgetBindingTableCheck();
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testTheSameSubjectReusesItsOpenConversation(): void
    {
        $conversation = $this->getConversationsService()->findNewestPurposedConversationOfAgentSpaceForSubject($this->buildAgentSpace(), self::PURPOSE_NAME, AIConversationSubject::of('\\' . self::SUBJECT_CLASS, 1));

        self::assertSame(101, (int)$conversation?->id);
    }

    public function testAnotherSubjectFindsNoConversationSoTheStartCreatesANewOne(): void
    {
        self::assertNull($this->getConversationsService()->findNewestPurposedConversationOfAgentSpaceForSubject($this->buildAgentSpace(), self::PURPOSE_NAME, AIConversationSubject::of(self::SUBJECT_CLASS, 4)));
        self::assertNull(
            $this->getConversationsService()->findNewestPurposedConversationOfAgentSpaceForSubject($this->buildAgentSpace(), self::PURPOSE_NAME, AIConversationSubject::of('App\\Domain\\Other\\Record', 1)),
            'the same id of another class is another subject'
        );
    }

    public function testAStartWithoutASubjectReusesOnlyTheConversationWithoutOne(): void
    {
        $conversation = $this->getConversationsService()->findNewestPurposedConversationOfAgentSpace($this->buildAgentSpace(), self::PURPOSE_NAME);

        self::assertSame(100, (int)$conversation?->id, 'the newer conversations of tickets are never the singleton of the space and purpose');
    }

    public function testAClosedConversationOfASubjectIsNotReusedUnlessThePurposeKeepsFinishedOnes(): void
    {
        $subject = AIConversationSubject::of(self::SUBJECT_CLASS, 3);
        self::assertNull($this->getConversationsService()->findNewestPurposedConversationOfAgentSpaceForSubject($this->buildAgentSpace(), self::PURPOSE_NAME, $subject));
        self::assertSame(
            103,
            (int)$this->getConversationsService()->findNewestPurposedConversationOfAgentSpaceForSubject($this->buildAgentSpace(), self::PURPOSE_NAME, $subject, true)?->id
        );
    }

    public function testABindingIsStoredOncePerConversation(): void
    {
        $this->insertPurposedConversation(104);
        $conversation = new AIConversation();
        $conversation->id = 104;
        $conversation->agentSpaceId = self::AGENT_SPACE_ID;
        $conversation->purposeName = self::PURPOSE_NAME;

        $binding = $this->getBindingsService()->bindSubjectToConversation($conversation, AIConversationSubject::of('\\' . self::SUBJECT_CLASS, 5));

        self::assertNotNull($binding);
        $storedRow = $this->findStoredRow(DBAIConversationSubjectBindingModel::getTableName(), (int)$binding->id);
        self::assertSame(self::SUBJECT_CLASS, $storedRow['subjectEntityClass'], 'stored without the leading backslash');
        self::assertSame(104, (int)$storedRow['aiConversationId']);
        self::assertSame(
            104,
            (int)$this->getConversationsService()->findNewestPurposedConversationOfAgentSpaceForSubject($this->buildAgentSpace(), self::PURPOSE_NAME, AIConversationSubject::of(self::SUBJECT_CLASS, 5))?->id
        );

        $this->expectException(\Throwable::class);
        $this->getBindingsService()->bindSubjectToConversation($conversation, AIConversationSubject::of(self::SUBJECT_CLASS, 6));
    }

    public function testWithoutTheSubjectTableTheOldSingletonHoldsAndNothingFails(): void
    {
        $this->replaceService(AIConversationSubjectBindingsService::class, new AIConversationSubjectBindingsServiceWithoutTheTable());

        $withSubject = $this->getConversationsService()->findNewestPurposedConversationOfAgentSpaceForSubject($this->buildAgentSpace(), self::PURPOSE_NAME, AIConversationSubject::of(self::SUBJECT_CLASS, 1));
        $withoutSubject = $this->getConversationsService()->findNewestPurposedConversationOfAgentSpace($this->buildAgentSpace(), self::PURPOSE_NAME);
        $conversation = new AIConversation();
        $conversation->id = 100;
        $conversation->agentSpaceId = self::AGENT_SPACE_ID;
        $conversation->purposeName = self::PURPOSE_NAME;

        self::assertSame(102, (int)$withSubject?->id, 'no reuse by subject: the newest open conversation of the space and purpose, as before');
        self::assertSame(102, (int)$withoutSubject?->id);
        self::assertNull($this->getBindingsService()->bindSubjectToConversation($conversation, AIConversationSubject::of(self::SUBJECT_CLASS, 9)));
    }

    public function testTheTableCheckFindsTheTable(): void
    {
        self::assertTrue((new AIConversationSubjectBindingsService())->isBindingTableAvailable());
    }

    protected function insertPurposedConversation(int $conversationId, string $runState = AIConversationRunState::IDLE): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), [
            'id' => $conversationId,
            'accountId' => self::ACCOUNT_ID,
            'agentSpaceId' => self::AGENT_SPACE_ID,
            'purposeName' => self::PURPOSE_NAME,
            'runState' => $runState,
        ]);
    }

    protected function insertBinding(int $conversationId, int $subjectEntityId): void
    {
        $this->insertRow(DBAIConversationSubjectBindingModel::getTableName(), [
            'aiConversationId' => $conversationId,
            'agentSpaceId' => self::AGENT_SPACE_ID,
            'purposeName' => self::PURPOSE_NAME,
            'subjectEntityClass' => self::SUBJECT_CLASS,
            'subjectEntityId' => $subjectEntityId,
        ]);
    }

    protected function buildAgentSpace(): AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = self::AGENT_SPACE_ID;
        $agentSpace->accountId = self::ACCOUNT_ID;
        return $agentSpace;
    }

    protected function getConversationsService(): AIConversationsService
    {
        /** @var AIConversationsService $aIConversationsService */
        $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
        return $aIConversationsService;
    }

    protected function getBindingsService(): AIConversationSubjectBindingsService
    {
        /** @var AIConversationSubjectBindingsService $aIConversationSubjectBindingsService */
        $aIConversationSubjectBindingsService = DDDService::instance()->getService(AIConversationSubjectBindingsService::class);
        return $aIConversationSubjectBindingsService;
    }
}

/** A host before the DDL: the subject table does not exist. */
class AIConversationSubjectBindingsServiceWithoutTheTable extends AIConversationSubjectBindingsService
{
    public function isBindingTableAvailable(): bool
    {
        return false;
    }
}
