<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubject;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubjectDescription;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Subjects\DBAIConversationSubjectBindingModel;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\AIConversationSubjectBindingsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Entities\Roles\Roles;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Services\AuthService;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineEntityRegistry;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Subjects\AIConversationSubjectDescriberDouble;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * Free conversations about one record, on both engines: a conversation without a purpose (in a space or at account
 * level) is bound with what the describer said; the record's conversations are listed across spaces and purposes
 * under the caller's read rights, never those of another account or of another record; a free conversation of a
 * record is never the singleton of a purposed start for it; what the describer said is stored once on the
 * conversation's working state and the model's text is rendered from it; a describer that fails or is missing leaves
 * the conversation bound without a record segment; a host without the table lists and stores nothing.
 */
class AIConversationFreeSubjectsDatabaseTest extends DatabaseTestCase
{
    protected const int ACCOUNT_ID = 11;

    protected const int OTHER_ACCOUNT_ID = 12;

    protected const int AGENT_SPACE_ID = 21;

    protected const int SECOND_AGENT_SPACE_ID = 22;

    protected const int OTHER_ACCOUNTS_AGENT_SPACE_ID = 23;

    protected const string PURPOSE_NAME = 'Support.AnswerTicket';

    /** The record's class: the space class, the one stored entity class the package itself can read. */
    protected const string SUBJECT_CLASS = AgentSpace::class;

    protected function setUp(): void
    {
        parent::setUp();
        AIConversationSubjectBindingsService::forgetBindingTableCheck();
        $this->getBindingsService()->setAIConversationSubjectDescribers([]);
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'first@example.test']);
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::OTHER_ACCOUNT_ID, 'email' => 'second@example.test']);
        $this->insertAgentSpaceRow(['id' => self::AGENT_SPACE_ID, 'accountId' => self::ACCOUNT_ID]);
        $this->insertAgentSpaceRow(['id' => self::SECOND_AGENT_SPACE_ID, 'accountId' => self::ACCOUNT_ID]);
        $this->insertAgentSpaceRow(['id' => self::OTHER_ACCOUNTS_AGENT_SPACE_ID, 'accountId' => self::OTHER_ACCOUNT_ID]);
        // record 1: 200 free in the first space, 201 purposed in the first space, 202 free in the second space,
        // 203 free at account level; 204 is another account's; 205 is about record 2; 206 has no subject
        $this->insertConversation(200, self::ACCOUNT_ID, self::AGENT_SPACE_ID, null);
        $this->insertConversation(201, self::ACCOUNT_ID, self::AGENT_SPACE_ID, self::PURPOSE_NAME);
        $this->insertConversation(202, self::ACCOUNT_ID, self::SECOND_AGENT_SPACE_ID, null);
        $this->insertConversation(203, self::ACCOUNT_ID, null, null);
        $this->insertConversation(204, self::OTHER_ACCOUNT_ID, self::OTHER_ACCOUNTS_AGENT_SPACE_ID, null);
        $this->insertConversation(205, self::ACCOUNT_ID, self::AGENT_SPACE_ID, null);
        $this->insertConversation(206, self::ACCOUNT_ID, self::AGENT_SPACE_ID, null);
        $this->insertBinding(200, self::AGENT_SPACE_ID, null, 1);
        $this->insertBinding(201, self::AGENT_SPACE_ID, self::PURPOSE_NAME, 1);
        $this->insertBinding(202, self::SECOND_AGENT_SPACE_ID, null, 1);
        $this->insertBinding(203, null, null, 1);
        $this->insertBinding(204, self::OTHER_ACCOUNTS_AGENT_SPACE_ID, null, 1);
        $this->insertBinding(205, self::AGENT_SPACE_ID, null, 2);
        $this->authenticateAsAccount(self::ACCOUNT_ID);
    }

    protected function onTearDown(): void
    {
        AIConversationSubjectBindingsService::forgetBindingTableCheck();
        $this->getBindingsService()->setAIConversationSubjectDescribers([]);
    }

    public function testTheConversationsOfARecordAreListedAcrossSpacesAndPurposesForTheirAccountOnly(): void
    {
        $conversationIds = $this->findConversationIdsOfSubject(AIConversationSubject::of('\\' . self::SUBJECT_CLASS, 1));

        self::assertSame([200, 201, 202, 203], $conversationIds, 'free and purposed, both spaces and account level; not the other account\'s 204, not record 2\'s 205');
        self::assertSame([205], $this->findConversationIdsOfSubject(AIConversationSubject::of(self::SUBJECT_CLASS, 2)));
        self::assertSame([], $this->findConversationIdsOfSubject(AIConversationSubject::of(self::SUBJECT_CLASS, 3)));
        self::assertSame([], $this->findConversationIdsOfSubject(AIConversationSubject::of('App\\Domain\\Other\\Record', 1)), 'the same id of another class is another record');
    }

    public function testAnotherAccountReadsOnlyItsOwnConversationOfTheRecord(): void
    {
        $this->authenticateAsAccount(self::OTHER_ACCOUNT_ID);

        self::assertSame([204], $this->findConversationIdsOfSubject(AIConversationSubject::of(self::SUBJECT_CLASS, 1)));
    }

    public function testAFreeConversationOfARecordIsNeverTheSingletonOfAPurposedStartForIt(): void
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = self::AGENT_SPACE_ID;
        $agentSpace->accountId = self::ACCOUNT_ID;
        // 207: a newer FREE conversation of record 1 in the same space
        $this->insertConversation(207, self::ACCOUNT_ID, self::AGENT_SPACE_ID, null);
        $this->insertBinding(207, self::AGENT_SPACE_ID, null, 1);

        $singleton = $this->getConversationsService()->findNewestPurposedConversationOfAgentSpaceForSubject($agentSpace, self::PURPOSE_NAME, AIConversationSubject::of(self::SUBJECT_CLASS, 1));

        self::assertSame(201, (int)$singleton?->id);
    }

    public function testAFreeConversationIsBoundWithoutAPurposeAndWhatTheDescriberSaidIsStoredOnItsWorkingStateOnce(): void
    {
        $describer = new AIConversationSubjectDescriberDouble([self::SUBJECT_CLASS], AIConversationSubjectDescription::of('agent space 21', 'Bakery "Morning"', '/spaces/21'));
        $bindingsService = $this->getBindingsService();
        $bindingsService->setAIConversationSubjectDescribers([$describer]);
        $conversation = $this->findConversation(206);

        $binding = $bindingsService->bindSubjectToConversation($conversation, AIConversationSubject::of(self::SUBJECT_CLASS, self::AGENT_SPACE_ID));

        self::assertNotNull($binding);
        $storedRow = $this->findStoredRow(DBAIConversationSubjectBindingModel::getTableName(), (int)$binding->id);
        self::assertNull($storedRow['purposeName'], 'a free conversation has no purpose');
        self::assertSame(self::AGENT_SPACE_ID, (int)$storedRow['agentSpaceId']);
        self::assertSame(1, $describer->describeCalls, 'the record is read under the caller\'s rights and described once');
        $expectedText = "# Record of this conversation\n"
            . "This conversation is about one record of the application: agent space 21.\n"
            . 'Name: <untrusted-content source="record name">Bakery "Morning"</untrusted-content>' . "\n"
            . "Link: /spaces/21\n"
            . 'These lines were written when the conversation started. Read the record with your tools before you state its current state.';
        self::assertSame($expectedText, $conversation->findConversationSubjectBlock(), 'the conversation object of the drive reads it at once');
        self::assertSame($expectedText, $this->findConversation(206)->findConversationSubjectBlock(), 'and so does every later load (a worker, a reopen)');

        // a second description never replaces the stored one: the model's text stays byte-stable
        $this->getConversationsService()->persistSubjectDescriptionSlice($conversation, AIConversationSubjectDescription::of('agent space 21, renamed', 'Bakery & Café'));
        self::assertSame($expectedText, $this->findConversation(206)->findConversationSubjectBlock());
    }

    public function testAConversationAtAccountLevelIsBoundWithoutASpaceAndWithoutADescriberItHasNoRecordSegment(): void
    {
        $this->insertConversation(208, self::ACCOUNT_ID, null, null);
        $conversation = $this->findConversation(208);

        $binding = $this->getBindingsService()->bindSubjectToConversation($conversation, AIConversationSubject::of(self::SUBJECT_CLASS, 1));

        $storedRow = $this->findStoredRow(DBAIConversationSubjectBindingModel::getTableName(), (int)$binding?->id);
        self::assertNull($storedRow['agentSpaceId']);
        self::assertNull($storedRow['purposeName']);
        self::assertNull($this->findConversation(208)->findConversationSubjectBlock(), 'no describer answers: bound, listed, no segment');
        self::assertContains(208, $this->findConversationIdsOfSubject(AIConversationSubject::of(self::SUBJECT_CLASS, 1)));
    }

    public function testADescriberThatFailsLeavesTheConversationBoundWithoutARecordSegment(): void
    {
        $describer = new AIConversationSubjectDescriberDouble([self::SUBJECT_CLASS], AIConversationSubjectDescription::of('agent space 21'));
        $describer->fails = true;
        $this->getBindingsService()->setAIConversationSubjectDescribers([$describer]);
        $conversation = $this->findConversation(206);

        $binding = $this->getBindingsService()->bindSubjectToConversation($conversation, AIConversationSubject::of(self::SUBJECT_CLASS, self::AGENT_SPACE_ID));

        self::assertNotNull($binding, 'the start does not fail with the describer');
        self::assertNull($this->findConversation(206)->findConversationSubjectBlock());
    }

    public function testAConversationWithoutASubjectHasNoBindingAndAPurposedOneKeepsItsPurposeOnTheBinding(): void
    {
        self::assertNull($this->getBindingsService()->findBindingOfConversation(206));
        self::assertNull($this->findConversation(206)->findConversationSubjectBlock());
        self::assertSame(1, (int)$this->getBindingsService()->findBindingOfConversation(201)?->subjectEntityId);
        self::assertSame(self::PURPOSE_NAME, $this->getBindingsService()->findBindingOfConversation(201)?->purposeName);
        self::assertNull($this->getBindingsService()->findBindingOfConversation(200)?->purposeName);
    }

    public function testTheRecordsOfAListOfConversationsAreReadForTheCallersConversationsOnly(): void
    {
        $describer = new AIConversationSubjectDescriberDouble([self::SUBJECT_CLASS], AIConversationSubjectDescription::of('agent space 21', 'Bakery "Morning"', '/spaces/21'));
        $this->getBindingsService()->setAIConversationSubjectDescribers([$describer]);
        // 206 is bound now: its working state holds what the describer said; the rows of setUp() were bound without one
        $this->getBindingsService()->bindSubjectToConversation($this->findConversation(206), AIConversationSubject::of(self::SUBJECT_CLASS, self::AGENT_SPACE_ID));

        // a later request: the list reads its rows from the database, not the objects this one loaded before the bind
        DoctrineEntityRegistry::clear();
        EntityManagerFactory::getInstance()->clear();

        $boundSubjects = $this->getBindingsService()->findBoundSubjectsOfConversations($this->getConversationsService()->findAllVisibleToCustomer());
        $boundSubjectsByConversationId = [];
        foreach ($boundSubjects->getElements() as $boundSubject) {
            $boundSubjectsByConversationId[(int)$boundSubject->aiConversationId] = $boundSubject;
        }
        ksort($boundSubjectsByConversationId);
        self::assertSame($boundSubjectsByConversationId[206], $boundSubjects->findSubjectOfConversation(206), 'one element per conversation, keyed by the conversation');
        self::assertNull($boundSubjects->findSubjectOfConversation(204));

        self::assertSame([200, 201, 202, 203, 205, 206], array_keys($boundSubjectsByConversationId), 'the account\'s bound conversations; never the other account\'s 204');
        self::assertSame(1, $boundSubjectsByConversationId[200]->subjectEntityId);
        self::assertNull($boundSubjectsByConversationId[200]->purposeName);
        self::assertNull($boundSubjectsByConversationId[200]->name, 'bound before a describer existed');
        self::assertSame(self::PURPOSE_NAME, $boundSubjectsByConversationId[201]->purposeName);
        self::assertSame(2, $boundSubjectsByConversationId[205]->subjectEntityId);
        self::assertSame(self::SUBJECT_CLASS, $boundSubjectsByConversationId[206]->subjectEntityClass);
        self::assertSame('Bakery "Morning"', $boundSubjectsByConversationId[206]->name);
        self::assertSame('/spaces/21', $boundSubjectsByConversationId[206]->link);

        $this->authenticateAsAccount(self::OTHER_ACCOUNT_ID);
        $boundSubjectsOfTheOtherAccount = $this->getBindingsService()->findBoundSubjectsOfConversations($this->getConversationsService()->findAllVisibleToCustomer());
        self::assertSame(1, $boundSubjectsOfTheOtherAccount->count());
        self::assertSame(204, $boundSubjectsOfTheOtherAccount->first()?->aiConversationId);
        self::assertSame(0, $this->getBindingsService()->findBoundSubjectsOfConversations(new AIConversations())->count());
    }

    public function testWithoutTheSubjectTableARecordHasNoConversationsAndNothingFails(): void
    {
        $this->replaceService(AIConversationSubjectBindingsService::class, new AIConversationSubjectBindingsServiceWithoutTheTable());
        $conversation = $this->findConversation(206);
        self::assertSame(0, $this->getBindingsService()->findBoundSubjectsOfConversations($this->getConversationsService()->findAllVisibleToCustomer())->count());

        self::assertSame([], $this->findConversationIdsOfSubject(AIConversationSubject::of(self::SUBJECT_CLASS, 1)));
        self::assertNull($this->getBindingsService()->findBindingOfConversation(200));
        self::assertNull($this->getBindingsService()->bindSubjectToConversation($conversation, AIConversationSubject::of(self::SUBJECT_CLASS, self::AGENT_SPACE_ID)));
        self::assertNull($this->findConversation(206)->findConversationSubjectBlock(), 'nothing is described for a subject that is not stored');
    }

    /** The stored conversation, read without the entity cache. */
    protected function findConversation(int $conversationId): AIConversation
    {
        $conversation = $this->getConversationsService()->find($conversationId, false);
        self::assertInstanceOf(AIConversation::class, $conversation);
        return $conversation;
    }

    /** @return int[] ascending */
    protected function findConversationIdsOfSubject(AIConversationSubject $aIConversationSubject): array
    {
        $conversationIds = [];
        foreach ($this->getConversationsService()->findAllVisibleToCustomerOfSubject($aIConversationSubject)->getElements() as $conversation) {
            $conversationIds[] = (int)$conversation->id;
        }
        sort($conversationIds);
        return $conversationIds;
    }

    protected function insertConversation(int $conversationId, int $accountId, ?int $agentSpaceId, ?string $purposeName): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), [
            'id' => $conversationId,
            'accountId' => $accountId,
            'agentSpaceId' => $agentSpaceId,
            'purposeName' => $purposeName,
            'runState' => AIConversationRunState::IDLE,
        ]);
    }

    protected function insertBinding(int $conversationId, ?int $agentSpaceId, ?string $purposeName, int $subjectEntityId): void
    {
        $this->insertRow(DBAIConversationSubjectBindingModel::getTableName(), [
            'aiConversationId' => $conversationId,
            'agentSpaceId' => $agentSpaceId,
            'purposeName' => $purposeName,
            'subjectEntityClass' => self::SUBJECT_CLASS,
            'subjectEntityId' => $subjectEntityId,
        ]);
    }

    /** Makes the account `$accountId` the authenticated one, without the admin role. */
    protected function authenticateAsAccount(int $accountId): void
    {
        $role = new Role();
        $role->setName('USER');
        $roles = new Roles();
        $roles->add($role);
        $account = new Account();
        $account->id = $accountId;
        $account->roles = $roles;
        AuthService::instance()->setAccount($account);
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
