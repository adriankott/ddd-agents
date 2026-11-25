<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentTransientMessageIds;
use DDD\Domain\AI\Services\Conversations\Agent\AgentSkillPreloadDecisionsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentTurnStartDecisionBatchService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationModelCallsService;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationPurposeRunsService;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The turn HEAD of the loop (`AIConversationAgentService::runConversationLoop()` up to its first model call): the
 * speculative cleanup, the system prompt, the turn-start decisions sent, advanced and applied as ONE batch, the
 * purpose's variants and turn-start notice after the batch, then the step's cleanup and the plan-item preload (no model
 * call) right before the first model call. The loop's services are replaced by recording doubles; the model-call double
 * stops the loop at its first call.
 *
 * The turn head never reaches the database: every case runs with no entity manager in the process and asserts that
 * none was created — a database access, also one whose failure the loop swallows, creates one first
 * ({@see EntityManagerFactory::getInstance()}). So a run with a database (DDD_AGENTS_TEST_DRIVER set) writes nothing,
 * and a run without one cannot pass by a swallowed connection error.
 */
class TurnHeadTest extends DecisionSiteOutcomeTestCase
{
    protected TurnHeadDecisionBatchServiceDouble $turnHeadDecisionBatchServiceDouble;

    /** @var array<string, mixed> The entity managers of the process before the case, restored after it */
    protected array $entityManagersBeforeTheCase = [];

    protected function setUp(): void
    {
        parent::setUp();
        $entityManagerInstances = new ReflectionProperty(EntityManagerFactory::class, 'instances');
        $this->entityManagersBeforeTheCase = $entityManagerInstances->getValue();
        $entityManagerInstances->setValue(null, []);
        $this->turnHeadDecisionBatchServiceDouble = new TurnHeadDecisionBatchServiceDouble($this->worldLog);
        $this->replaceService(AgentTurnStartDecisionBatchService::class, $this->turnHeadDecisionBatchServiceDouble);
        $this->replaceService(AIConversationModelCallsService::class, new TurnHeadModelCallsServiceDouble($this->worldLog));
        $this->replaceService(AIConversationMessagesService::class, new TurnHeadMessagesServiceDouble($this->worldLog));
        $this->replaceService(AIConversationPurposeRunsService::class, new TurnHeadPurposeRunsServiceDouble($this->worldLog));
        $this->replaceService(AgentSkillPreloadDecisionsService::class, new TurnHeadSkillPreloadServiceDouble($this->worldLog));
    }

    public function testTheTurnHeadRunsTheBatchThenTheVariantsThenThePurposeNoticeBeforeTheFirstModelCall(): void
    {
        $this->runTurnHead(AgentRun::forTurnEntry());

        self::assertSame([
            'turn head: speculative capability cleanup',
            'turn head: system prompt',
            'turn head: turn-start decisions sent',
            'turn head: turn-start decisions advanced',
            'turn head: turn-start decisions applied',
            'turn head: turn-start decisions not applied are received',
            'turn head: purpose variants resolved',
            'turn head: purpose turn-start notice',
            'turn head: speculative capability cleanup',
            'turn head: plan-item skill preload (no model call)',
            'turn head: model call 1',
        ], array_values(array_filter($this->worldLog->lines, static fn (string $line): bool => str_starts_with($line, 'turn head:'))));
    }

    public function testTheLoopHandsItsOwnRunToTheTurnStart(): void
    {
        $agentRun = AgentRun::forTurnEntry();

        $this->runTurnHead($agentRun);

        self::assertSame($agentRun, $this->turnHeadDecisionBatchServiceDouble->agentRun);
    }

    /**
     * The owner turn of the tables with its creation settings already stamped (so the loop stamps and persists
     * nothing) and without an id (so no overhead cost is booked on a row).
     */
    protected function stampedOwnerTurnConversation(): AIConversation
    {
        $conversation = $this->ownerTurnConversation();
        unset($conversation->id);
        $contextRetentionSettings = new ContextRetentionSettings();
        $contextRetentionSettings->skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP;
        $contextRetentionSettings->stateChangeDelivery = ContextRetentionSettings::STATE_CHANGE_DELIVERY_EVERY_CALL;
        $conversation->contextRetentionSettings = $contextRetentionSettings;
        return $conversation;
    }

    protected function onTearDown(): void
    {
        (new ReflectionProperty(EntityManagerFactory::class, 'instances'))->setValue(null, $this->entityManagersBeforeTheCase);
        parent::onTearDown();
    }

    protected function runTurnHead(AgentRun $agentRun): void
    {
        /** @var AIConversationAgentService $aIConversationAgentService */
        $aIConversationAgentService = (new ReflectionClass(AIConversationAgentService::class))->newInstanceWithoutConstructor();
        try {
            (new ReflectionMethod($aIConversationAgentService, 'runConversationLoop'))
                ->invokeArgs($aIConversationAgentService, [$agentRun, $this->stampedOwnerTurnConversation(), false, AgentTransientMessageIds::startingAt(3), null, null]);
            self::fail('the model-call double stops the loop at its first call');
        } catch (TurnHeadStoppedAtTheModelCallException $stopped) {
            self::assertSame('the turn head ends at the first model call', $stopped->getMessage());
        }
        self::assertSame([], EntityManagerFactory::getInstantiatedScopes(), 'the turn head never reaches the database');
    }
}
