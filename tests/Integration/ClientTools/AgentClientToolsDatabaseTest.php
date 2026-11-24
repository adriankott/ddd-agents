<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\ClientTools;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest;
use DDD\Domain\AI\Entities\ClientTools\AgentUIState;
use DDD\Domain\AI\Repo\DB\ClientTools\DBAgentClientAttachmentModel;
use DDD\Domain\AI\Repo\DB\ClientTools\DBAgentClientToolRequestModel;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Services\ClientTools\AgentClientAttachmentsService;
use DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Tests\Agents\Support\DatabaseTestCase;
use DDD\Infrastructure\Services\DDDService;

/**
 * The client tool channel on MariaDB and PostgreSQL: a client's attachment is stamped and found as the target, a
 * request is delivered once to its client and answered once, the answer text is blanked, and the purge expires an
 * overdue request and deletes old rows of both tables.
 */
class AgentClientToolsDatabaseTest extends DatabaseTestCase
{
    public const int ACCOUNT_ID = 811;

    public const int CONVERSATION_ID = 4101;

    public const string CLIENT_ID = 'client-0001-abcd';

    protected ?Account $accountBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountBefore = AuthService::instance()->getAccount();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'client-tools@example.test']);
        $this->insertRow(DBAIConversationModel::getTableName(), ['id' => self::CONVERSATION_ID, 'accountId' => self::ACCOUNT_ID]);
        $account = Account::newInstance();
        $account->id = self::ACCOUNT_ID;
        AuthService::instance()->setAccount($account);
    }

    protected function tearDown(): void
    {
        AuthService::instance()->setAccount($this->accountBefore);
        parent::tearDown();
    }

    public function testAStampedClientIsTheTargetAndKeepsItsUIState(): void
    {
        $agentUIState = new AgentUIState();
        $agentUIState->routePath = '/orders/42';
        $agentUIState->visible = true;
        $this->getAttachmentsService()->stampClient(self::CONVERSATION_ID, self::ACCOUNT_ID, self::CLIENT_ID, $agentUIState, true);

        $attachment = $this->getAttachmentsService()->findAttachmentOfClient(self::CONVERSATION_ID, self::CLIENT_ID);
        self::assertNotNull($attachment);
        self::assertSame('/orders/42', $attachment->route);
        self::assertTrue($attachment->visible);
        self::assertSame('/orders/42', $attachment->getUIState()?->routePath);
        self::assertSame(self::CLIENT_ID, $this->getAttachmentsService()->findTargetAttachment(self::CONVERSATION_ID, new DateTime())?->clientId);
    }

    public function testARequestIsDeliveredToItsClientAndAnsweredOnce(): void
    {
        $requestId = $this->insertRequestRow(AgentClientToolRequest::STATUS_OPEN, '+20 seconds');
        $requestsService = $this->getRequestsService();

        self::assertNull($requestsService->findDeliveryForClient(self::CONVERSATION_ID, 'another-client-0002'));
        self::assertNotNull($requestsService->findDeliveryForClient(self::CONVERSATION_ID, self::CLIENT_ID));
        // delivered just now: not delivered again before the redelivery interval
        self::assertNull($requestsService->findDeliveryForClient(self::CONVERSATION_ID, self::CLIENT_ID));

        $requestsService->answerRequest(self::CONVERSATION_ID, $requestId, self::CLIENT_ID, AgentClientToolRequest::OUTCOME_OK, '# Orders', '{"rows":3}');
        $storedRow = $this->findStoredRow(DBAgentClientToolRequestModel::getTableName(), $requestId);
        self::assertSame(AgentClientToolRequest::STATUS_ANSWERED, $storedRow['status']);
        self::assertSame('# Orders', $storedRow['answerText']);

        $requestsService->blankAnswerText($requestId);
        self::assertNull($this->findStoredRow(DBAgentClientToolRequestModel::getTableName(), $requestId)['answerText']);

        $this->expectException(ConflictException::class);
        $requestsService->answerRequest(self::CONVERSATION_ID, $requestId, self::CLIENT_ID, AgentClientToolRequest::OUTCOME_OK, 'again', null);
    }

    public function testThePurgeExpiresOverdueRequestsAndDeletesOldRows(): void
    {
        $overdueRequestId = $this->insertRequestRow(AgentClientToolRequest::STATUS_DELIVERED, '-1 minute');
        $oldRequestId = $this->insertRequestRow(AgentClientToolRequest::STATUS_ANSWERED, '-40 days', '-40 days');
        $this->insertRow(DBAgentClientAttachmentModel::getTableName(), [
            'conversationId' => self::CONVERSATION_ID,
            'accountId' => self::ACCOUNT_ID,
            'clientId' => self::CLIENT_ID,
            'lastSeenAt' => (new DateTime('-2 days'))->format('Y-m-d H:i:s'),
            'created' => (new DateTime('-2 days'))->format('Y-m-d H:i:s'),
            'updated' => (new DateTime('-2 days'))->format('Y-m-d H:i:s'),
        ]);

        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $purgeResult = $this->getRequestsService()->purge();
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }

        self::assertSame(1, $purgeResult->expiredRequestCount);
        self::assertSame(1, $purgeResult->deletedRequestCount);
        self::assertSame(1, $purgeResult->deletedAttachmentCount);
        self::assertSame(AgentClientToolRequest::STATUS_EXPIRED, $this->findStoredRow(DBAgentClientToolRequestModel::getTableName(), $overdueRequestId)['status']);
        self::assertNull($this->findStoredRow(DBAgentClientToolRequestModel::getTableName(), $oldRequestId));
    }

    protected function insertRequestRow(string $status, string $expiresAtModifier, string $createdModifier = 'now'): int
    {
        static $nextRequestId = 9100;
        $requestId = ++$nextRequestId;
        $created = (new DateTime($createdModifier))->format('Y-m-d H:i:s');
        $this->insertRow(DBAgentClientToolRequestModel::getTableName(), [
            'id' => $requestId,
            'conversationId' => self::CONVERSATION_ID,
            'accountId' => self::ACCOUNT_ID,
            'requestType' => AgentClientToolRequest::TYPE_READ_SCREEN,
            'argumentsJson' => '{}',
            'targetClientId' => self::CLIENT_ID,
            'status' => $status,
            'expiresAt' => (new DateTime($expiresAtModifier))->format('Y-m-d H:i:s'),
            'created' => $created,
            'updated' => $created,
        ]);
        return $requestId;
    }

    protected function getAttachmentsService(): AgentClientAttachmentsService
    {
        /** @var AgentClientAttachmentsService $agentClientAttachmentsService */
        $agentClientAttachmentsService = DDDService::instance()->getService(AgentClientAttachmentsService::class);
        return $agentClientAttachmentsService;
    }

    protected function getRequestsService(): AgentClientToolRequestsService
    {
        /** @var AgentClientToolRequestsService $agentClientToolRequestsService */
        $agentClientToolRequestsService = DDDService::instance()->getService(AgentClientToolRequestsService::class);
        return $agentClientToolRequestsService;
    }
}
