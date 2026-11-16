<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals\ConversationPurge;

use DDD\Domain\AI\Entities\Evals\ConversationPurge\AIConversationEvalPurgeKeys;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The ids of one purge batch by kind: the module's kinds and any kind of an application, each a list of distinct ids.
 */
class AIConversationEvalPurgeKeysTest extends UnitTestCase
{
    public function testEachKindHoldsItsDistinctIdsInTheOrderTheyWereSet(): void
    {
        $purgeKeys = AIConversationEvalPurgeKeys::newInstance();
        $purgeKeys->setIdsOfKind(AIConversationEvalPurgeKeys::KIND_CONVERSATION, [10, '11', 10]);
        $purgeKeys->setIdsOfKind('call', [3]);
        $purgeKeys->addIdsOfKind('call', [4, 3]);
        $purgeKeys->addIdsOfKind(AIConversationEvalPurgeKeys::KIND_MESSAGE, [100]);

        self::assertSame([10, 11], $purgeKeys->getConversationIds());
        self::assertSame([100], $purgeKeys->getMessageIds());
        self::assertSame([3, 4], $purgeKeys->getIdsOfKind('call'), 'an application\'s kind');
        self::assertSame([], $purgeKeys->getDelegationBatchIds(), 'a kind nobody set has no ids');
        self::assertSame(2, $purgeKeys->countIdsOfKind(AIConversationEvalPurgeKeys::KIND_CONVERSATION));
        self::assertSame([AIConversationEvalPurgeKeys::KIND_CONVERSATION, 'call', AIConversationEvalPurgeKeys::KIND_MESSAGE], $purgeKeys->getKeyKinds());

        $purgeKeys->setIdsOfKind('call', []);
        self::assertSame([], $purgeKeys->getIdsOfKind('call'), 'set replaces what the kind held');
    }
}
