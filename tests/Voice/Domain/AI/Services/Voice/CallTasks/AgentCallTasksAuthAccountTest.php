<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallTasks;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Services\AccountsService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker\FixedAccountsService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The account a voice task runs as: the worker has no signed-in account of its own, so the bridge signs in the
 * conversation's account — only when none is signed in (a signed-in account is never replaced) and only for a real
 * account id. Nothing signs it out again (the worker's next job starts from a fresh auth state). The account is read
 * through the accounts service, which the test replaces in the container.
 */
class AgentCallTasksAuthAccountTest extends AgentVoiceIntegrationTestCase
{
    protected FixedAccountsService $fixedAccountsService;

    protected ?Account $accountSignedInBeforeTheTest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accountSignedInBeforeTheTest = AuthService::instance()->getAccount();
        $noAccount = null;
        AuthService::instance()->setAccount($noAccount);
        StaticRegistry::$entityServices = [];
        $this->fixedAccountsService = new FixedAccountsService();
        $this->fixedAccountsService->accountsById[5] = FixedAccountsService::buildAccount(5);
        $this->fixedAccountsService->accountsById[8] = FixedAccountsService::buildAccount(8);
        $this->replaceService(AccountsService::class, $this->fixedAccountsService);
    }

    protected function onTearDown(): void
    {
        AuthService::instance()->setAccount($this->accountSignedInBeforeTheTest);
        parent::onTearDown();
    }

    public function testTheConversationsAccountIsSignedInWhenNoneIs(): void
    {
        (new StagedAgentCallTasksService())->ensureAuthAccountForConversation(static::buildConversationOfAccount(5));

        static::assertSame(5, AuthService::instance()->getAccount()?->id);
        static::assertSame([5], $this->fixedAccountsService->foundAccountIds);
    }

    public function testASignedInAccountIsNeverReplaced(): void
    {
        $signedInAccount = FixedAccountsService::buildAccount(8);
        AuthService::instance()->setAccount($signedInAccount);

        (new StagedAgentCallTasksService())->ensureAuthAccountForConversation(static::buildConversationOfAccount(5));

        static::assertSame(8, AuthService::instance()->getAccount()?->id);
        static::assertSame([], $this->fixedAccountsService->foundAccountIds);
    }

    /** @return array<string, array{0: int|null}> */
    public static function provideConversationsOfNoAccount(): array
    {
        return ['no account' => [null], 'account zero' => [0]];
    }

    #[DataProvider('provideConversationsOfNoAccount')]
    public function testAConversationOfNoAccountSignsInNobody(?int $conversationAccountId): void
    {
        (new StagedAgentCallTasksService())->ensureAuthAccountForConversation(static::buildConversationOfAccount($conversationAccountId));

        static::assertNull(AuthService::instance()->getAccount());
        static::assertSame([], $this->fixedAccountsService->foundAccountIds);
    }

    public function testStartingATaskSignsInTheAccountFirstAndLeavesItSignedIn(): void
    {
        $agentCallTasksService = new StagedAgentCallTasksService();

        $agentCallTasksService->startAgentTaskOnTranscript(static::buildConversationOfAccount(5));

        static::assertSame(5, AuthService::instance()->getAccount()?->id, 'signed in although the conversation is gone');
        static::assertSame([41], $agentCallTasksService->freshConversationReads);
    }

    protected static function buildConversationOfAccount(?int $accountId): AIConversation
    {
        $aiConversation = AIConversation::newInstance();
        $aiConversation->id = 41;
        $aiConversation->accountId = $accountId;
        $aiConversation->runState = AIConversationRunState::IDLE;

        return $aiConversation;
    }
}
