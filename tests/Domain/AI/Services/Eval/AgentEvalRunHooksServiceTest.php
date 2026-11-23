<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\OfferedSkillCatalog;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\AI\Interfaces\AIConversationProgressReporterInterface;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionClass;
use RuntimeException;

/**
 * The "no application" defaults of what an application adds to an evaluation run: the scope is the stored agent space
 * the case or the caller names, a conversation gets the scope's stored space and the module never creates one, every
 * scope is eligible, a withheld skill is described by its offer step, no requirement is unmet, nothing is torn down,
 * released, prepared, curated or kept alive, and the external-write tools are the tools that act outward.
 */
class AgentEvalRunHooksServiceTest extends IntegrationTestCase
{
    protected AgentSpacesServiceWithKnownSpacesDouble $agentSpacesServiceDouble;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agentSpacesServiceDouble = new AgentSpacesServiceWithKnownSpacesDouble();
        $this->replaceService(AgentSpacesService::class, $this->agentSpacesServiceDouble);
        StaticRegistry::$entityServices = [];
    }

    protected function onTearDown(): void
    {
        StaticRegistry::$entityServices = [];
    }

    protected function buildAccount(int $accountId): Account
    {
        $account = new Account();
        $account->id = $accountId;
        return $account;
    }

    protected function buildStoredAgentSpace(int $agentSpaceId): AgentSpace
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = $agentSpaceId;
        return $agentSpace;
    }

    protected function buildConversationWithoutId(): AIConversation
    {
        /** @var AIConversation $conversation */
        $conversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        return $conversation;
    }

    protected function knowAgentSpaceWithOwner(int $agentSpaceId, int $ownerAccountId): AgentSpace
    {
        $agentSpace = $this->buildStoredAgentSpace($agentSpaceId);
        $this->agentSpacesServiceDouble->agentSpacesById[$agentSpaceId] = $agentSpace;
        $this->agentSpacesServiceDouble->ownerAccountsByAgentSpaceId[$agentSpaceId] = $this->buildAccount($ownerAccountId);
        return $agentSpace;
    }

    public function testTheHooksServiceIsResolvedThroughTheContainer(): void
    {
        self::assertInstanceOf(AgentEvalRunHooksService::class, DDDService::instance()->getService(AgentEvalRunHooksService::class));
    }

    public function testTheScopeIsTheStoredAgentSpaceTheCaseNamesReadWithTheRightsLifted(): void
    {
        $agentSpace = $this->knowAgentSpaceWithOwner(17, 5);
        $this->knowAgentSpaceWithOwner(18, 6);

        $agentEvalScope = (new AgentEvalRunHooksService())->resolveEvalScope(AgentEvalCase::fromArray(['id' => 'c', 'agentSpaceId' => 17]), 18);

        self::assertSame(17, $agentEvalScope->getAgentSpaceId(), 'the case wins over the caller');
        self::assertSame($agentSpace, $agentEvalScope->getStoredAgentSpace());
        self::assertSame(5, $agentEvalScope->getOwnerAccountId());
        self::assertSame([], $agentEvalScope->getApplicationValues());
        self::assertSame([true], $this->agentSpacesServiceDouble->rightsLiftedPerRead);
        self::assertTrue(DBEntity::getApplyRightsRestrictions(), 'the rights restrictions are back after the read');
    }

    public function testACaseThatNamesNoAgentSpaceRunsInTheOneTheCallerNames(): void
    {
        $this->knowAgentSpaceWithOwner(18, 6);

        $agentEvalScope = (new AgentEvalRunHooksService())->resolveEvalScope(AgentEvalCase::fromArray(['id' => 'c']), 18);

        self::assertSame(18, $agentEvalScope->getAgentSpaceId());
        self::assertSame(6, $agentEvalScope->getOwnerAccountId());
    }

    public function testACaseWithoutAnyAgentSpaceIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The case 'c' names no agent space and the caller gave none.");
        (new AgentEvalRunHooksService())->resolveEvalScope(AgentEvalCase::fromArray(['id' => 'c']));
    }

    public function testACaseNamesItsAgentSpaceByReferenceAndTheReferenceWinsOverTheCaller(): void
    {
        $this->knowAgentSpaceWithOwner(17, 5);
        $this->knowAgentSpaceWithOwner(18, 6);
        $agentEvalRunHooksService = new AgentEvalRunHooksServiceWithNamedAgentSpacesDouble();
        $agentEvalRunHooksService->agentSpaceIdsByName = ['evaluation admin' => 17];

        $agentEvalScope = $agentEvalRunHooksService->resolveEvalScope(AgentEvalCase::fromArray(['id' => 'c', 'agentSpaceId' => '{{ref:agentSpace: evaluation admin }}']), 18);

        self::assertSame(17, $agentEvalScope->getAgentSpaceId());
        self::assertSame(5, $agentEvalScope->getOwnerAccountId());
    }

    public function testAReferenceThatDoesNotResolveFailsTheCaseAndNeverFallsBackToTheCallersSpace(): void
    {
        $this->knowAgentSpaceWithOwner(18, 6);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The case 'c' names the agent space {{ref:agentSpace:missing}}, which does not resolve");
        (new AgentEvalRunHooksService())->resolveEvalScope(AgentEvalCase::fromArray(['id' => 'c', 'agentSpaceId' => '{{ref:agentSpace:missing}}']), 18);
    }

    public function testAnAgentSpaceThatIsNotStoredIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Agent space 99 not found');
        (new AgentEvalRunHooksService())->resolveEvalScope(AgentEvalCase::fromArray(['agentSpaceId' => 99]));
    }

    public function testAnAgentSpaceWithoutAKnownOwnerIsRefusedAndTheRightsAreBack(): void
    {
        $this->agentSpacesServiceDouble->agentSpacesById[17] = $this->buildStoredAgentSpace(17);

        try {
            (new AgentEvalRunHooksService())->resolveEvalScope(AgentEvalCase::fromArray(['agentSpaceId' => 17]));
            self::fail('a space without an owner must be refused');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('Could not resolve the account that owns agent space 17', $runtimeException->getMessage());
        }
        self::assertTrue(DBEntity::getApplyRightsRestrictions());
    }

    public function testANewConversationGetsTheStoredAgentSpaceOfTheScope(): void
    {
        $agentSpace = $this->buildStoredAgentSpace(17);
        $conversation = $this->buildConversationWithoutId();

        (new AgentEvalRunHooksService())->attachNewConversationToScope($conversation, AgentEvalScope::forAgentSpace($agentSpace, $this->buildAccount(5)));

        self::assertSame(17, $conversation->agentSpaceId);
        self::assertSame($agentSpace, $conversation->agentSpace);
        self::assertSame([0], $this->agentSpacesServiceDouble->attachedConversationIds, 'the relations of the space are attached');
    }

    public function testAScopeWithoutAStoredAgentSpaceLeavesTheConversationAtAccountLevelAndCreatesNoSpace(): void
    {
        $agentEvalRunHooksService = new AgentEvalRunHooksService();
        $agentEvalScope = AgentEvalScope::forAgentSpace(new AgentSpace(), $this->buildAccount(5));
        $conversation = $this->buildConversationWithoutId();

        $agentEvalRunHooksService->attachNewConversationToScope($conversation, $agentEvalScope);
        $agentEvalRunHooksService->storeAgentSpaceOfScopeBeforeConversationIsStored($conversation, $agentEvalScope);

        self::assertFalse(isset($conversation->agentSpaceId));
        self::assertNull($agentEvalRunHooksService->findOrStoreAgentSpaceForHarnessToolCalls($agentEvalScope), 'a harness call runs at account level');
        self::assertFalse($agentEvalScope->hasStoredAgentSpace());
        self::assertSame([], $this->agentSpacesServiceDouble->attachedConversationIds);
    }

    public function testAConversationAboutToBeStoredGetsTheStoredAgentSpaceAndKeepsOneItHas(): void
    {
        $agentEvalRunHooksService = new AgentEvalRunHooksService();
        $agentSpace = $this->buildStoredAgentSpace(17);
        $agentEvalScope = AgentEvalScope::forAgentSpace($agentSpace, $this->buildAccount(5));

        $conversationWithoutSpace = $this->buildConversationWithoutId();
        $agentEvalRunHooksService->storeAgentSpaceOfScopeBeforeConversationIsStored($conversationWithoutSpace, $agentEvalScope);
        self::assertSame(17, $conversationWithoutSpace->agentSpaceId);
        self::assertSame($agentSpace, $conversationWithoutSpace->agentSpace);

        $conversationWithSpace = $this->buildConversationWithoutId();
        $conversationWithSpace->agentSpaceId = 23;
        $agentEvalRunHooksService->storeAgentSpaceOfScopeBeforeConversationIsStored($conversationWithSpace, $agentEvalScope);
        self::assertSame(23, $conversationWithSpace->agentSpaceId);

        self::assertSame($agentSpace, $agentEvalRunHooksService->findOrStoreAgentSpaceForHarnessToolCalls($agentEvalScope));
    }

    public function testNothingIsTornDownReleasedPreparedOrCuratedAndNothingIsReported(): void
    {
        $agentEvalRunHooksService = new AgentEvalRunHooksService();
        $progressReporter = new AgentEvalProgressLinesRecorder();
        $agentEvalScope = AgentEvalScope::forAgentSpace($this->buildStoredAgentSpace(17), $this->buildAccount(5));
        $conversation = $this->buildConversationWithoutId();
        $conversation->id = 41;

        self::assertSame([], $agentEvalRunHooksService->tearDownHostResidueOfConversation($conversation, [41, 42], $progressReporter));
        self::assertSame([], $agentEvalRunHooksService->releaseHostHoldsAfterHarnessCalls($agentEvalScope, new DateTime(), $progressReporter));
        $agentEvalRunHooksService->prepareConversationChannelBeforeDrive($conversation, OwnerDevice::phoneCall());
        $agentEvalRunHooksService->curateMemoryAfterDrivenTurn($conversation);

        self::assertSame([], $progressReporter->progressLines);
    }

    public function testAHarnessToolCallGetsNoArgumentOfThePlatform(): void
    {
        $agentEvalScope = AgentEvalScope::forAgentSpace($this->buildStoredAgentSpace(17), $this->buildAccount(5));

        self::assertSame([], (new AgentEvalRunHooksService())->getHarnessToolCallArguments($agentEvalScope));
    }

    public function testEveryScopeIsEligibleAndNoRequirementIsUnmet(): void
    {
        $agentEvalRunHooksService = new AgentEvalRunHooksService();
        $agentEvalScope = AgentEvalScope::forAgentSpace($this->buildStoredAgentSpace(17), $this->buildAccount(5));

        self::assertNull($agentEvalRunHooksService->findIneligibleScopeReason($agentEvalScope));
        self::assertTrue($agentEvalRunHooksService->findUnmetRequirementDetails($agentEvalScope, AgentEvalCase::fromArray(['requires' => ['dailyLimit']]))->isEmpty());
    }

    public function testAWithheldSkillIsDescribedByTheFirstOfferStepThatDroppedIt(): void
    {
        $reachableSkillCatalog = new OfferedSkillCatalog();
        $reachableSkillCatalog->rejectedSkillNamesByStep = [
            'featureFlags' => ['Notes.Write'],
            'accountRights' => ['Notes.Write', 'Calendar.Publishing'],
        ];
        $withheldSkills = AISkills::newInstance();
        foreach (['Notes.Write', 'Calendar.Publishing', 'Calendar.Events'] as $withheldSkillName) {
            $withheldSkill = new AISkill();
            $withheldSkill->name = $withheldSkillName;
            $withheldSkills->add($withheldSkill);
        }

        $withheldSkillReasons = (new AgentEvalRunHooksService())->findWithheldSkillReasons($this->buildConversationWithoutId(), $reachableSkillCatalog, $withheldSkills);

        self::assertSame([
            'Notes.Write' => "the offer step 'featureFlags' withholds it",
            'Calendar.Publishing' => "the offer step 'accountRights' withholds it",
            'Calendar.Events' => 'no root skill that unlocks it is offered',
        ], $withheldSkillReasons);
    }

    public function testTheExternalWriteToolsAreTheToolsThatDeclareAnOutwardAction(): void
    {
        $externalWriteToolNames = (new AgentEvalRunHooksService())->getExternalWriteToolNames();

        self::assertContains('grantMandate', $externalWriteToolNames, 'the module\'s own outward tool');
        self::assertSame(array_values(array_unique($externalWriteToolNames)), $externalWriteToolNames);
        foreach ($externalWriteToolNames as $externalWriteToolName) {
            self::assertTrue(MCPToolEffectCatalog::isOutwardActionToolName($externalWriteToolName), $externalWriteToolName);
        }
    }
}
