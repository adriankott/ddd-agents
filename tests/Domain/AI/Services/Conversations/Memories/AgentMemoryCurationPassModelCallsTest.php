<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryCurationModelCall;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryReconcileResult;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryCurationModelCallsService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryCurationService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Libs\Config;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * The result of one curation pass carries every model call of the pass in call order: the extraction, then the
 * reconciliation and the conflict resolution; and the extraction alone when the pass ends because it found no
 * candidate. No model and no table: the model calls answer from a script, the store is empty.
 */
class AgentMemoryCurationPassModelCallsTest extends IntegrationTestCase
{
    protected const string TWO_CANDIDATES = '{"operations":['
        . '{"operation":"ADD","type":"FACT","subject":"BUSINESS","content":"has 4 refrigerated counters"},'
        . '{"operation":"ADD","type":"FACT","subject":"BUSINESS","content":"opens at 8","resolvesConflict":true}]}';

    protected const string ONE_NOOP = '{"operations":[{"operation":"NOOP","content":"already known"}]}';

    protected AgentMemoryCurationModelCallsServiceDouble $modelCallsServiceDouble;

    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
        $this->modelCallsServiceDouble = new AgentMemoryCurationModelCallsServiceDouble();
        $this->replaceService(AgentMemoryCurationModelCallsService::class, $this->modelCallsServiceDouble);
        $this->replaceService(AgentMemoryStoreService::class, new AgentMemoryStoreWithoutNeighboursDouble());
    }

    public function testThePassCarriesTheExtractionAndReconciliationCallsInCallOrder(): void
    {
        $this->modelCallsServiceDouble->scriptedAnswers = [self::TWO_CANDIDATES, self::ONE_NOOP, self::ONE_NOOP];

        $agentMemoryReconcileResult = $this->curateOneOwnerMessage();

        $modelCalls = $agentMemoryReconcileResult->modelCalls->getElements();
        self::assertCount(3, $modelCalls);
        self::assertSame(
            [
                AgentMemoryCurationModelCall::STAGE_EXTRACTION,
                AgentMemoryCurationModelCall::STAGE_RECONCILIATION,
                AgentMemoryCurationModelCall::STAGE_CONFLICT_RESOLUTION,
            ],
            [$modelCalls[0]->stage, $modelCalls[1]->stage, $modelCalls[2]->stage]
        );
        self::assertSame(self::TWO_CANDIDATES, $modelCalls[0]->rawResultText);
        self::assertSame([100, 200, 300], [$modelCalls[0]->inputTokens, $modelCalls[1]->inputTokens, $modelCalls[2]->inputTokens]);
        self::assertSame(2, $agentMemoryReconcileResult->operations->count(), 'the operations are the reconciliation\'s, as before');
        self::assertSame(0, $agentMemoryReconcileResult->applied->count());
    }

    public function testAPassThatEndsAfterAnExtractionWithoutACandidateCarriesTheExtractionCall(): void
    {
        $this->modelCallsServiceDouble->scriptedAnswers = ['{"operations":[]}'];

        $agentMemoryReconcileResult = $this->curateOneOwnerMessage();

        self::assertSame([AgentMemoryCurationModelCall::STAGE_EXTRACTION], $this->modelCallsServiceDouble->loadedStages);
        self::assertSame(1, $agentMemoryReconcileResult->modelCalls->count());
        self::assertSame(AgentMemoryCurationModelCall::STAGE_EXTRACTION, $agentMemoryReconcileResult->modelCalls->first()->stage);
        self::assertSame(0, $agentMemoryReconcileResult->operations->count());
    }

    public function testThePassRecordsBothAttemptsOfARepeatedExtraction(): void
    {
        $this->modelCallsServiceDouble->scriptedAnswers = ['no json at all', '{"operations":[]}'];

        $agentMemoryReconcileResult = $this->curateOneOwnerMessage();

        $modelCalls = $agentMemoryReconcileResult->modelCalls->getElements();
        self::assertCount(2, $modelCalls);
        self::assertSame([1, 2], [$modelCalls[0]->attempt, $modelCalls[1]->attempt]);
        self::assertSame('no json at all', $modelCalls[0]->rawResultText);
    }

    protected function curateOneOwnerMessage(): AgentMemoryReconcileResult
    {
        $aIConversationMessage = AIConversationMessage::newInstance();
        $aIConversationMessage->id = 41;
        $aIConversationMessage->role = AIConversationMessage::ROLE_USER;
        $aIConversationMessage->message = 'We have 4 refrigerated counters, and we open at 8 now.';
        $delta = AIConversationMessages::newInstance();
        $delta->add($aIConversationMessage);

        $agentMemoryCurationService = new AgentMemoryCurationService();
        return $agentMemoryCurationService->curateAndApply($delta, null, 1, 5, 'en', 'opening hours', 41);
    }
}
