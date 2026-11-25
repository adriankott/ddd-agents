<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\ClientTools;

use DDD\Domain\AI\Entities\ClientTools\AgentClientPresence;
use DDD\Domain\AI\Entities\ClientTools\AgentUIState;
use DDD\Domain\AI\Services\ClientTools\AgentClientPresenceService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Realtime\SharedCacheWithCountersDouble;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The statement of a client that shows no conversation
 * ({@see AgentClientPresenceService::recordClientStatementWithoutConversation()}): one entry per account under a key
 * of its own, written when the account has none or its newest one is at least the throttle old (whichever client
 * stated it), never for a value that is no client id or an account id below 1; it replaces nothing a client that
 * shows a conversation stated. {@see AgentClientPresenceService::findNewestPresenceOfAccount()} answers a fresh
 * conversation statement first and falls back to it; {@see AgentClientPresenceService::findTargetPresence()} never
 * picks it. A failing cache never throws.
 *
 * The service reads the clock itself: ages are staged through the stored `lastSeenAt`. An age staged as "now minus N"
 * can read as N + 1 when a second turns between staging and the read, so the cases below the throttle (10 s) and
 * inside the fresh window (25 s) stage a second of margin; the exact 25 / 26 s boundary is pinned on
 * {@see AgentClientPresence::isFresh()} with a fixed "now".
 */
class AgentClientPresenceWithoutConversationTest extends UnitTestCase
{
    public const int ACCOUNT_ID = 3;

    public const int OTHER_ACCOUNT_ID = 4;

    public const int CONVERSATION_ID = 70;

    public const string POLLING_CLIENT_ID = 'poll-client-0001';

    public const string OTHER_POLLING_CLIENT_ID = 'poll-client-0002';

    public const string CONVERSATION_CLIENT_ID = 'conversation-client-0001';

    protected SharedCacheWithCountersDouble $sharedCache;

    protected SharedCachePresenceServiceDouble $presenceService;

    protected function setUp(): void
    {
        $this->sharedCache = new SharedCacheWithCountersDouble();
        $this->presenceService = new SharedCachePresenceServiceDouble($this->sharedCache);
    }

    public static function getStatementKey(int $accountId = self::ACCOUNT_ID): string
    {
        return AgentClientPresenceService::ACCOUNT_WITHOUT_CONVERSATION_KEY_PREFIX . $accountId;
    }

    public function testTheStatementKeyAndTheThrottleAreTheDocumentedOnes(): void
    {
        self::assertSame('agent-client-presence-without-conversation-3', static::getStatementKey());
        self::assertSame(10, AgentClientPresenceService::STATEMENT_THROTTLE_SECONDS);
        self::assertSame(900, AgentClientPresenceService::PRESENCE_TTL_SECONDS);
        self::assertSame(25, AgentClientPresence::FRESH_FOR_SECONDS);
    }

    public function testAStatementCarriesTheAccountTheClientAndTheTimeAndNothingElse(): void
    {
        $timeBefore = time();
        $this->presenceService->recordClientStatementWithoutConversation(static::ACCOUNT_ID, static::POLLING_CLIENT_ID);
        $timeAfter = time();

        self::assertSame([static::getStatementKey()], array_keys($this->sharedCache->valuesByKey));
        self::assertSame(1, $this->sharedCache->batchWrites);
        self::assertSame(900, $this->sharedCache->ttlSecondsByKey[static::getStatementKey()]);
        $statementEntry = $this->sharedCache->valuesByKey[static::getStatementKey()];
        $lastSeenAt = $statementEntry['lastSeenAt'];
        self::assertGreaterThanOrEqual($timeBefore, $lastSeenAt);
        self::assertLessThanOrEqual($timeAfter, $lastSeenAt);
        self::assertSame(
            [
                'conversationId' => 0,
                'accountId' => static::ACCOUNT_ID,
                'clientId' => static::POLLING_CLIENT_ID,
                'lastSeenAt' => $lastSeenAt,
                'visible' => null,
                'route' => null,
                'lastOwnerSendAt' => null,
                'uiStateJson' => null,
            ],
            $statementEntry
        );
    }

    /** @return array<string, array{int, string|null}> */
    public static function provideStatementsThatAreIgnored(): array
    {
        return [
            'no client id' => [self::ACCOUNT_ID, null],
            'an empty client id' => [self::ACCOUNT_ID, ''],
            'a client id under 8 characters' => [self::ACCOUNT_ID, 'short'],
            'a client id with a space' => [self::ACCOUNT_ID, 'has a space in it'],
            'a client id of 65 characters' => [self::ACCOUNT_ID, str_repeat('a', 65)],
            'a client id with another character' => [self::ACCOUNT_ID, 'client_id_0001'],
            'account 0' => [0, self::POLLING_CLIENT_ID],
            'a negative account' => [-1, self::POLLING_CLIENT_ID],
        ];
    }

    #[DataProvider('provideStatementsThatAreIgnored')]
    public function testAValueThatIsNoClientIdOrAnAccountBelowOneIsIgnored(int $accountId, ?string $clientId): void
    {
        $this->presenceService->recordClientStatementWithoutConversation($accountId, $clientId);

        self::assertSame([], $this->sharedCache->valuesByKey);
        self::assertSame(0, $this->sharedCache->batchWrites);
        self::assertSame(0, $this->sharedCache->batchReads, 'refused before the cache is asked');
    }

    public function testTheLongestAndTheShortestClientIdAreStated(): void
    {
        $this->presenceService->recordClientStatementWithoutConversation(static::ACCOUNT_ID, str_repeat('a', 8));
        $this->presenceService->recordClientStatementWithoutConversation(static::OTHER_ACCOUNT_ID, str_repeat('b', 64));

        self::assertSame(str_repeat('a', 8), $this->sharedCache->valuesByKey[static::getStatementKey()]['clientId']);
        self::assertSame(str_repeat('b', 64), $this->sharedCache->valuesByKey[static::getStatementKey(static::OTHER_ACCOUNT_ID)]['clientId']);
    }

    public function testUnderTheThrottleNoClientOfTheAccountWritesAndEachStatementCostsOneCacheRead(): void
    {
        $this->presenceService->recordClientStatementWithoutConversation(static::ACCOUNT_ID, static::POLLING_CLIENT_ID);
        $this->stageStatementAge(static::getStatementKey(), 8);
        $storedEntry = $this->sharedCache->valuesByKey[static::getStatementKey()];
        $readsBefore = $this->sharedCache->batchReads;

        $this->presenceService->recordClientStatementWithoutConversation(static::ACCOUNT_ID, static::POLLING_CLIENT_ID);
        $this->presenceService->recordClientStatementWithoutConversation(static::ACCOUNT_ID, static::OTHER_POLLING_CLIENT_ID);

        self::assertSame(1, $this->sharedCache->batchWrites, 'the throttle is per account, whichever client states');
        self::assertSame($storedEntry, $this->sharedCache->valuesByKey[static::getStatementKey()]);
        self::assertSame($readsBefore + 2, $this->sharedCache->batchReads, 'one cache read per statement');
    }

    public function testAtTheThrottleTheReadingClientReplacesTheStoredOne(): void
    {
        $this->presenceService->recordClientStatementWithoutConversation(static::ACCOUNT_ID, static::POLLING_CLIENT_ID);
        $this->stageStatementAge(static::getStatementKey(), AgentClientPresenceService::STATEMENT_THROTTLE_SECONDS);
        $timeBefore = time();

        $this->presenceService->recordClientStatementWithoutConversation(static::ACCOUNT_ID, static::OTHER_POLLING_CLIENT_ID);

        self::assertSame(2, $this->sharedCache->batchWrites);
        self::assertSame(static::OTHER_POLLING_CLIENT_ID, $this->sharedCache->valuesByKey[static::getStatementKey()]['clientId']);
        self::assertGreaterThanOrEqual($timeBefore, $this->sharedCache->valuesByKey[static::getStatementKey()]['lastSeenAt']);
    }

    public function testTheThrottleOfOneAccountDoesNotHoldAnother(): void
    {
        $this->presenceService->recordClientStatementWithoutConversation(static::ACCOUNT_ID, static::POLLING_CLIENT_ID);
        $this->presenceService->recordClientStatementWithoutConversation(static::OTHER_ACCOUNT_ID, static::POLLING_CLIENT_ID);

        self::assertSame(2, $this->sharedCache->batchWrites);
        self::assertSame(static::OTHER_ACCOUNT_ID, $this->sharedCache->valuesByKey[static::getStatementKey(static::OTHER_ACCOUNT_ID)]['accountId']);
    }

    public function testTheStatementIsTheAccountsPresenceWhileItIsFresh(): void
    {
        $this->presenceService->recordClientStatementWithoutConversation(static::ACCOUNT_ID, static::POLLING_CLIENT_ID);
        $this->stageStatementAge(static::getStatementKey(), AgentClientPresence::FRESH_FOR_SECONDS - 1);

        $newestPresence = $this->presenceService->findNewestPresenceOfAccount(static::ACCOUNT_ID);

        self::assertSame(0, $newestPresence?->conversationId);
        self::assertSame(static::POLLING_CLIENT_ID, $newestPresence?->clientId);
        self::assertSame(static::ACCOUNT_ID, $newestPresence?->accountId);
        self::assertNull($this->presenceService->findNewestPresenceOfAccount(static::OTHER_ACCOUNT_ID), 'another account has no presence');
    }

    public function testAStatementOlderThanTheFreshWindowIsNoPresence(): void
    {
        $this->presenceService->recordClientStatementWithoutConversation(static::ACCOUNT_ID, static::POLLING_CLIENT_ID);
        $this->stageStatementAge(static::getStatementKey(), AgentClientPresence::FRESH_FOR_SECONDS + 1);

        self::assertNull($this->presenceService->findNewestPresenceOfAccount(static::ACCOUNT_ID));
    }

    public function testTheFreshWindowEndsAfterTwentyFiveSeconds(): void
    {
        $now = DateTime::fromTimestamp(1_800_000_000);
        $agentClientPresence = new AgentClientPresence();
        $agentClientPresence->clientId = static::POLLING_CLIENT_ID;

        $agentClientPresence->lastSeenAt = 1_800_000_000 - 25;
        self::assertTrue($agentClientPresence->isFresh($now));
        $agentClientPresence->lastSeenAt = 1_800_000_000 - 26;
        self::assertFalse($agentClientPresence->isFresh($now));
    }

    public function testTheStatementsOfAClientThatShowsAConversationStayByteIdentical(): void
    {
        $this->presenceService->recordClientStatement(static::CONVERSATION_ID, static::ACCOUNT_ID, static::CONVERSATION_CLIENT_ID, static::buildUIState('/orders/42'), true);
        $conversationKey = AgentClientPresenceService::CONVERSATION_KEY_PREFIX . static::CONVERSATION_ID;
        $accountKey = AgentClientPresenceService::ACCOUNT_KEY_PREFIX . static::ACCOUNT_ID;
        $serializedBefore = [serialize($this->sharedCache->valuesByKey[$conversationKey]), serialize($this->sharedCache->valuesByKey[$accountKey])];
        $ttlsBefore = [$this->sharedCache->ttlSecondsByKey[$conversationKey], $this->sharedCache->ttlSecondsByKey[$accountKey]];

        $this->presenceService->recordClientStatementWithoutConversation(static::ACCOUNT_ID, static::POLLING_CLIENT_ID);
        $this->stageStatementAge(static::getStatementKey(), AgentClientPresenceService::STATEMENT_THROTTLE_SECONDS);
        $this->presenceService->recordClientStatementWithoutConversation(static::ACCOUNT_ID, static::CONVERSATION_CLIENT_ID);

        self::assertSame($serializedBefore, [serialize($this->sharedCache->valuesByKey[$conversationKey]), serialize($this->sharedCache->valuesByKey[$accountKey])]);
        self::assertSame($ttlsBefore, [$this->sharedCache->ttlSecondsByKey[$conversationKey], $this->sharedCache->ttlSecondsByKey[$accountKey]]);
        self::assertEqualsCanonicalizing([$conversationKey, $accountKey, static::getStatementKey()], array_keys($this->sharedCache->valuesByKey));
    }

    public function testAFreshConversationStatementIsPreferredWithItsRouteAndUIStateThenTheStatementWithoutOne(): void
    {
        $this->presenceService->recordClientStatement(static::CONVERSATION_ID, static::ACCOUNT_ID, static::CONVERSATION_CLIENT_ID, static::buildUIState('/orders/42'));
        $this->stageStatementAge(AgentClientPresenceService::ACCOUNT_KEY_PREFIX . static::ACCOUNT_ID, 5);
        $this->presenceService->recordClientStatementWithoutConversation(static::ACCOUNT_ID, static::POLLING_CLIENT_ID);

        $newestPresence = $this->presenceService->findNewestPresenceOfAccount(static::ACCOUNT_ID);
        self::assertSame(static::CONVERSATION_ID, $newestPresence?->conversationId, 'preferred although the statement without a conversation is newer');
        self::assertSame(static::CONVERSATION_CLIENT_ID, $newestPresence?->clientId);
        self::assertSame('/orders/42', $newestPresence?->route);
        self::assertSame('/orders/42', $newestPresence?->getUIState()?->routePath);

        $this->stageStatementAge(AgentClientPresenceService::ACCOUNT_KEY_PREFIX . static::ACCOUNT_ID, 60);
        $newestPresence = $this->presenceService->findNewestPresenceOfAccount(static::ACCOUNT_ID);
        self::assertSame(0, $newestPresence?->conversationId, 'the conversation statement is stale: the polling client answers');
        self::assertSame(static::POLLING_CLIENT_ID, $newestPresence?->clientId);
        self::assertNull($newestPresence?->route);
    }

    public function testAStatementWithoutAConversationIsNeverATargetOfAClientToolRequest(): void
    {
        $this->presenceService->recordClientStatementWithoutConversation(static::ACCOUNT_ID, static::POLLING_CLIENT_ID);

        self::assertNull($this->presenceService->findTargetPresence(static::CONVERSATION_ID));
        self::assertNull($this->presenceService->findTargetPresence(0));
        self::assertCount(0, $this->presenceService->findPresencesOfConversation(0)->getElements());

        $this->presenceService->recordClientStatement(static::CONVERSATION_ID, static::ACCOUNT_ID, static::CONVERSATION_CLIENT_ID, null);
        self::assertSame(static::CONVERSATION_CLIENT_ID, $this->presenceService->findTargetPresence(static::CONVERSATION_ID)?->clientId);
    }

    public function testAFailingCacheNeverThrowsAndWritesNothing(): void
    {
        $this->sharedCache->failsEveryCall = true;

        $this->presenceService->recordClientStatementWithoutConversation(static::ACCOUNT_ID, static::POLLING_CLIENT_ID);
        self::assertNull($this->presenceService->findNewestPresenceOfAccount(static::ACCOUNT_ID));

        $this->sharedCache->failsEveryCall = false;
        self::assertSame([], $this->sharedCache->valuesByKey);
    }

    public function testWithoutASharedCacheNothingIsStatedAndNoPresenceIsRead(): void
    {
        $presenceServiceWithoutCache = new SharedCachePresenceServiceDouble(null);

        $presenceServiceWithoutCache->recordClientStatementWithoutConversation(static::ACCOUNT_ID, static::POLLING_CLIENT_ID);

        self::assertNull($presenceServiceWithoutCache->findNewestPresenceOfAccount(static::ACCOUNT_ID));
    }

    /** Moves the stored statement under `$cacheKey` `$bySeconds` into the past. */
    protected function stageStatementAge(string $cacheKey, int $bySeconds): void
    {
        $this->sharedCache->valuesByKey[$cacheKey]['lastSeenAt'] -= $bySeconds;
    }

    protected static function buildUIState(string $routePath): AgentUIState
    {
        $agentUIState = new AgentUIState();
        $agentUIState->routePath = $routePath;
        $agentUIState->visible = true;
        return $agentUIState;
    }
}
