<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Integration\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\DatabaseTestCase;

/**
 * The reopen of an entry point, on both engines: the package's read reopens only a conversation without a purpose, the
 * purposed read only a conversation of its purpose, so an application that starts a purposed conversation from an
 * entry point reopens it on the next click instead of starting a new one every time.
 */
class AIConversationPurposedEntryPointReopenDatabaseTest extends DatabaseTestCase
{
    protected const int ACCOUNT_ID = 31;

    protected const int AGENT_SPACE_ID = 41;

    protected const string ENTRY_POINT_KEY = 'support.tickets.answer';

    protected const string PURPOSE_NAME = 'Support.AnswerTicket';

    protected function setUp(): void
    {
        parent::setUp();
        DDDService::instance()->deactivateEntityRightsRestrictions();
        $this->insertRow(DBAccountModel::getTableName(), ['id' => self::ACCOUNT_ID, 'email' => 'entry@example.test']);
        $this->insertAgentSpaceRow(['id' => self::AGENT_SPACE_ID, 'accountId' => self::ACCOUNT_ID]);
        // 300: the entry point without a purpose; 301 (newer): the same entry point with the purpose
        $this->insertEntryPointConversation(300, null);
        $this->insertEntryPointConversation(301, self::PURPOSE_NAME);
    }

    protected function onTearDown(): void
    {
        DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
    }

    public function testThePurposedReadReopensTheConversationOfItsPurpose(): void
    {
        $conversation = $this->getConversationsService()->findReopenablePurposedConversationForEntryPoint(
            $this->buildAccount(),
            $this->buildAgentSpace(),
            self::ENTRY_POINT_KEY,
            self::PURPOSE_NAME,
            new DateTime()
        );

        self::assertSame(301, (int)$conversation?->id);
    }

    public function testThePackagesReadStillReopensOnlyAConversationWithoutAPurpose(): void
    {
        $conversation = $this->getConversationsService()->findReopenableConversationForEntryPoint(
            $this->buildAccount(),
            $this->buildAgentSpace(),
            self::ENTRY_POINT_KEY,
            new DateTime()
        );

        self::assertSame(300, (int)$conversation?->id, 'the newer purposed conversation is not the one the package reopens');
    }

    public function testAnotherPurposeReopensNothing(): void
    {
        self::assertNull($this->getConversationsService()->findReopenablePurposedConversationForEntryPoint(
            $this->buildAccount(),
            $this->buildAgentSpace(),
            self::ENTRY_POINT_KEY,
            'Support.Other',
            new DateTime()
        ));
    }

    protected function insertEntryPointConversation(int $conversationId, ?string $purposeName): void
    {
        $this->insertRow(DBAIConversationModel::getTableName(), [
            'id' => $conversationId,
            'accountId' => self::ACCOUNT_ID,
            'agentSpaceId' => self::AGENT_SPACE_ID,
            'entryPointKey' => self::ENTRY_POINT_KEY,
            'purposeName' => $purposeName,
            'runState' => 'IDLE',
            'created' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function buildAccount(): Account
    {
        $account = new Account();
        $account->id = self::ACCOUNT_ID;
        return $account;
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
}
