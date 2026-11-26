<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalBlueprintProviderService;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalsService;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalStoreService;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\Date;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\Agents\AgentGoalBlueprintMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentGoalsMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentGoalAddRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentGoalBlueprintGetRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentGoalBlueprintMcpResponseDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentGoalsGetRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentGoalsMcpResponseDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentGoalUpdateStatusRequestDto;
use DDD\Tests\Agents\Domain\AI\Entities\Conversations\Memories\ShopLabelAgentSpacesService;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Goals\AgentGoalsSwitch;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Goals\HostBackedAgentGoalStoreService;
use DDD\Tests\Agents\Support\IntegrationTestCase;

/**
 * The goal tools over a host's goal store (the port in the container's place): the switch decides whether the tools and
 * the skill are offered; `getGoals` reads the scope and names the space as the application does; `addGoal` saves the
 * owner's goal, stores the agent's proposal with a plain note (no marker), writes nothing for an exact restatement and
 * lets the application refuse a goal before the write (the marker, nothing saved); `updateGoalStatus` confirms a
 * proposal and never touches a goal of another account; without the goal table every tool completes with "not
 * available"; `getGoalBlueprint` is offered only while an application binds a provider.
 */
class AgentGoalsToolsTest extends IntegrationTestCase
{
    public const int ACCOUNT_ID = 1;

    public const int AGENT_SPACE_ID = 2;

    protected HostBackedAgentGoalStoreService $hostBackedAgentGoalStoreService;

    protected ShopLabelAgentSpacesService $shopLabelAgentSpacesService;

    protected AgentGoalsSwitch $agentGoalsSwitch;

    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
        $this->hostBackedAgentGoalStoreService = new HostBackedAgentGoalStoreService();
        $this->replaceService(AgentGoalStoreService::class, $this->hostBackedAgentGoalStoreService);
        $this->shopLabelAgentSpacesService = new ShopLabelAgentSpacesService();
        $this->shopLabelAgentSpacesService->shopIdsByAgentSpaceId = [self::AGENT_SPACE_ID => 4711];
        $this->replaceService(AgentSpacesService::class, $this->shopLabelAgentSpacesService);
        $this->agentGoalsSwitch = new AgentGoalsSwitch();
        $this->agentGoalsSwitch->switchGoals(true);
    }

    protected function onTearDown(): void
    {
        $this->agentGoalsSwitch->restore();
        AgentGoalStoreService::forgetGoalTableCheck();
    }

    public function testWhileGoalsAreOffNeitherPackageNorSkillIsOfferedAndACallIsRefused(): void
    {
        $this->agentGoalsSwitch->switchGoals(false);

        self::assertFalse(AgentGoalsMCPPackage::isAvailable());
        self::assertArrayNotHasKey(AISkill::AGENT_GOALS, AISkill::getPackageClassesBySkillName());
        try {
            (new AgentGoalsMCPPackage())->getGoals(new AgentGoalsGetRequestDto(), new AgentGoalsService(), $this->buildCallProvenance());
            self::fail('a direct call is refused while goals are off');
        } catch (BadRequestException $badRequestException) {
            self::assertSame(AgentGoalsMCPPackage::GOALS_OFF_MESSAGE, $badRequestException->getMessage());
        }

        $this->agentGoalsSwitch->switchGoals(true);
        self::assertTrue(AgentGoalsMCPPackage::isAvailable());
        self::assertSame([AgentGoalsMCPPackage::class], AISkill::getPackageClassesBySkillName()[AISkill::AGENT_GOALS] ?? null, 'without a provider the skill binds the goal tools only');
    }

    public function testGetGoalsReadsTheScopeAndNamesTheSpaceAsTheApplicationDoes(): void
    {
        $this->addHostGoal('Weekend catering is booked out', self::AGENT_SPACE_ID, 2);
        $this->addHostGoal('Answer in short sentences', null);
        $this->addHostGoal('A goal of another shop', 3);
        $droppedGoal = $this->addHostGoal('A food truck at the market', self::AGENT_SPACE_ID);
        $droppedGoal->status = AgentGoal::STATUS_DROPPED;

        $toolResultText = (new AgentGoalsMCPPackage())->getGoals(new AgentGoalsGetRequestDto(), new AgentGoalsService(), $this->buildCallProvenance())->toLlmMarkdown();

        self::assertSame(
            "- #500 [OWNER/LONG_TERM/ACTIVE/shop:4711] \"Weekend catering is booked out\" (USER_STATED; priority 2)\n"
            . '- #501 [OWNER/LONG_TERM/ACTIVE/account] "Answer in short sentences" (USER_STATED)',
            $toolResultText
        );
        $withFinishedRequestDto = new AgentGoalsGetRequestDto();
        $withFinishedRequestDto->includeFinished = true;
        self::assertStringContainsString('"A food truck at the market"', (new AgentGoalsMCPPackage())->getGoals($withFinishedRequestDto, new AgentGoalsService(), $this->buildCallProvenance())->toLlmMarkdown());
    }

    public function testAddGoalSavesTheOwnersGoalOnceAndStoresTheAgentsProposalWithAPlainNote(): void
    {
        $addRequestDto = new AgentGoalAddRequestDto();
        $addRequestDto->statement = 'The new seasonal menu is online.';
        $addRequestDto->horizonLevel = 'short-term';
        $addRequestDto->targetDate = '2027-04-30';

        $savedResponseDto = (new AgentGoalsMCPPackage())->addGoal($addRequestDto, new AgentGoalsService(), $this->buildCallProvenance());
        self::assertSame(AgentGoalsMcpResponseDto::NOTE_SAVED, $savedResponseDto->note);
        $savedGoal = $savedResponseDto->goals->first();
        self::assertSame([self::ACCOUNT_ID, self::AGENT_SPACE_ID], [$savedGoal->accountId, $savedGoal->agentSpaceId]);
        self::assertSame([AgentGoal::HOLDER_OWNER, AgentGoal::HORIZON_SHORT_TERM, AgentGoal::STATUS_ACTIVE], [$savedGoal->holder, $savedGoal->horizonLevel, $savedGoal->status]);
        self::assertSame('2027-04-30', $savedGoal->targetDate?->format(Date::DATE));

        $againResponseDto = (new AgentGoalsMCPPackage())->addGoal($addRequestDto, new AgentGoalsService(), $this->buildCallProvenance());
        self::assertSame(AgentGoalsMcpResponseDto::NOTE_ALREADY_SAVED, $againResponseDto->note);
        self::assertSame((int)$savedGoal->id, (int)$againResponseDto->goals->first()->id);

        $proposalRequestDto = new AgentGoalAddRequestDto();
        $proposalRequestDto->statement = 'Every review gets an answer within two days.';
        $proposalRequestDto->statedByOwner = false;
        $proposalRequestDto->blueprintKey = ' reviews.answered ';
        $proposalResponseDto = (new AgentGoalsMCPPackage())->addGoal($proposalRequestDto, new AgentGoalsService(), $this->buildCallProvenance());
        self::assertSame(AgentGoalsMcpResponseDto::NOTE_STORED_AS_PROPOSAL, $proposalResponseDto->note);
        self::assertStringStartsNotWith(ToolCall::RESULT_MARKER_DOWNGRADED_TO_PROPOSAL, (string)$proposalResponseDto->note, 'a stored proposal was saved as asked: no marker');
        self::assertSame([AgentGoal::HOLDER_AGENT, AgentGoal::STATUS_PROPOSED, 'reviews.answered'], [$proposalResponseDto->goals->first()->holder, $proposalResponseDto->goals->first()->status, $proposalResponseDto->goals->first()->blueprintKey]);
        self::assertSame(2, count($this->hostBackedAgentGoalStoreService->findWriteCalls()));
    }

    public function testAddGoalRefusesAnUnknownHorizonADayThatIsNoneAndAnEmptyStatement(): void
    {
        $refusals = [
            'horizonLevel is one of VISION, LONG_TERM, SHORT_TERM.' => ['A second shop', 'someday', null],
            'targetDate is a day like 2027-03-31.' => ['A second shop', null, '2027-02-30'],
            AgentGoalsService::STATEMENT_MISSING_MESSAGE => ['  ', null, null],
        ];
        foreach ($refusals as $expectedMessage => [$statement, $horizonLevel, $targetDate]) {
            $addRequestDto = new AgentGoalAddRequestDto();
            $addRequestDto->statement = $statement;
            $addRequestDto->horizonLevel = $horizonLevel;
            $addRequestDto->targetDate = $targetDate;
            try {
                (new AgentGoalsMCPPackage())->addGoal($addRequestDto, new AgentGoalsService(), $this->buildCallProvenance());
                self::fail('refused: ' . $expectedMessage);
            } catch (BadRequestException $badRequestException) {
                self::assertSame($expectedMessage, $badRequestException->getMessage());
            }
        }
        self::assertSame([], $this->hostBackedAgentGoalStoreService->findWriteCalls());
    }

    public function testTheApplicationMayRefuseAGoalBeforeTheWrite(): void
    {
        $agentGoalsMCPPackage = new AgentGoalsMCPPackageRefusingDouble();
        $addRequestDto = new AgentGoalAddRequestDto();
        $addRequestDto->statement = 'More guests than the competitor across the street.';

        $refusedResponseDto = $agentGoalsMCPPackage->addGoal($addRequestDto, new AgentGoalsService(), $this->buildCallProvenance());

        self::assertSame(
            ToolCall::RESULT_MARKER_DOWNGRADED_TO_PROPOSAL . ' — nothing saved: ' . AgentGoalsMCPPackageRefusingDouble::REFUSAL_REASON,
            $refusedResponseDto->note
        );
        self::assertSame(0, $refusedResponseDto->goals->count());
        self::assertSame([], $this->hostBackedAgentGoalStoreService->findWriteCalls());
        self::assertSame([self::ACCOUNT_ID . '/' . self::AGENT_SPACE_ID . '/' . AgentGoal::HOLDER_OWNER], $agentGoalsMCPPackage->askedGoalScopes, 'the hook sees the goal anchored and held');

        $addRequestDto->statement = 'More guests on weekday mornings.';
        self::assertSame(AgentGoalsMcpResponseDto::NOTE_SAVED, $agentGoalsMCPPackage->addGoal($addRequestDto, new AgentGoalsService(), $this->buildCallProvenance())->note);
    }

    public function testUpdateGoalStatusConfirmsAProposalAndNeverTouchesAGoalOfAnotherAccount(): void
    {
        $proposedGoal = $this->addHostGoal('Every review gets an answer within two days', self::AGENT_SPACE_ID);
        $proposedGoal->markHeldByOwnerOrAgent(false);
        $proposedGoal->status = AgentGoal::STATUS_PROPOSED;
        $foreignGoal = $this->addHostGoal('A goal of another account', null);
        $foreignGoal->accountId = 99;
        $updateRequestDto = new AgentGoalUpdateStatusRequestDto();
        $updateRequestDto->goalId = (int)$proposedGoal->id;
        $updateRequestDto->status = ' active ';

        $updatedResponseDto = (new AgentGoalsMCPPackage())->updateGoalStatus($updateRequestDto, new AgentGoalsService(), $this->buildCallProvenance());

        self::assertSame(AgentGoalsMcpResponseDto::NOTE_STATUS_UPDATED, $updatedResponseDto->note);
        self::assertSame([AgentGoal::STATUS_ACTIVE, AgentGoal::PROVENANCE_CONFIRMED], [$proposedGoal->status, $proposedGoal->provenance]);
        $updateRequestDto->goalId = (int)$foreignGoal->id;
        $this->expectException(NotFoundException::class);
        (new AgentGoalsMCPPackage())->updateGoalStatus($updateRequestDto, new AgentGoalsService(), $this->buildCallProvenance());
    }

    public function testWithoutTheGoalTableEveryToolCompletesWithNotAvailableAndNothingIsWritten(): void
    {
        $this->hostBackedAgentGoalStoreService->isAvailable = false;
        $addRequestDto = new AgentGoalAddRequestDto();
        $addRequestDto->statement = 'Weekend catering is booked out';
        $updateRequestDto = new AgentGoalUpdateStatusRequestDto();
        $updateRequestDto->goalId = 1;
        $updateRequestDto->status = AgentGoal::STATUS_ACHIEVED;
        $agentGoalsMCPPackage = new AgentGoalsMCPPackage();

        $readNote = $agentGoalsMCPPackage->getGoals(new AgentGoalsGetRequestDto(), new AgentGoalsService(), $this->buildCallProvenance())->note;
        $addNote = $agentGoalsMCPPackage->addGoal($addRequestDto, new AgentGoalsService(), $this->buildCallProvenance())->note;
        $updateNote = $agentGoalsMCPPackage->updateGoalStatus($updateRequestDto, new AgentGoalsService(), $this->buildCallProvenance())->note;

        self::assertSame(AgentGoalsMcpResponseDto::NOTE_REGISTER_NOT_AVAILABLE, $readNote);
        self::assertSame(ToolCall::RESULT_MARKER_DOWNGRADED_TO_PROPOSAL . ' — ' . AgentGoalsMcpResponseDto::NOTE_REGISTER_NOT_AVAILABLE, $addNote);
        self::assertSame($addNote, $updateNote);
        self::assertSame(['isGoalStoreAvailable', 'isGoalStoreAvailable', 'isGoalStoreAvailable'], $this->hostBackedAgentGoalStoreService->portCalls);
    }

    public function testThePackageStoreAsksTheCatalogOnceForATableAndAgainAfterTheRecheckForAMissingOne(): void
    {
        AgentGoalStoreService::forgetGoalTableCheck();
        $agentGoalStoreService = new AgentGoalStoreServiceCountingTableChecksDouble();

        self::assertFalse($agentGoalStoreService->isGoalStoreAvailable());
        self::assertFalse($agentGoalStoreService->isGoalStoreAvailable());
        self::assertSame(1, $agentGoalStoreService->askedCatalogCount, 'a missing table is not asked again within the recheck time');

        $agentGoalStoreService->goalTableCount = 1;
        AgentGoalStoreService::forgetGoalTableCheck();
        self::assertTrue($agentGoalStoreService->isGoalStoreAvailable());
        self::assertTrue($agentGoalStoreService->isGoalStoreAvailable());
        self::assertSame(2, $agentGoalStoreService->askedCatalogCount);
    }

    public function testWithoutAProviderTheBlueprintToolIsNotOfferedAndACallIsRefused(): void
    {
        self::assertFalse(AgentGoalBlueprintProviderService::isBlueprintProviderBound());
        self::assertFalse(AgentGoalBlueprintMCPPackage::isAvailable());
        try {
            (new AgentGoalBlueprintMCPPackage())->getGoalBlueprint(new AgentGoalBlueprintGetRequestDto(), new AgentGoalBlueprintProviderService(), $this->buildCallProvenance());
            self::fail('no tree is bound');
        } catch (BadRequestException $badRequestException) {
            self::assertSame(AgentGoalBlueprintMCPPackage::NO_BLUEPRINT_MESSAGE, $badRequestException->getMessage());
        }
    }

    public function testABoundProviderOffersTheBlueprintToolUnderTheGoalSkill(): void
    {
        $bakeryGoalBlueprintProviderService = new BakeryGoalBlueprintProviderService();
        $this->replaceService(AgentGoalBlueprintProviderService::class, $bakeryGoalBlueprintProviderService);

        self::assertTrue(AgentGoalBlueprintMCPPackage::isAvailable());
        self::assertSame([AgentGoalsMCPPackage::class, AgentGoalBlueprintMCPPackage::class], AISkill::getPackageClassesBySkillName()[AISkill::AGENT_GOALS] ?? null);
        $toolResultText = (new AgentGoalBlueprintMCPPackage())->getGoalBlueprint(new AgentGoalBlueprintGetRequestDto(), $bakeryGoalBlueprintProviderService, $this->buildCallProvenance())->toLlmMarkdown();
        self::assertStringContainsString('    ⬜ `presence.photos` Fresh photos every month', $toolResultText);
        self::assertStringContainsString(AgentGoalBlueprintMcpResponseDto::LEGEND, $toolResultText);
        self::assertSame([self::ACCOUNT_ID . '/' . self::AGENT_SPACE_ID], $bakeryGoalBlueprintProviderService->askedScopes);

        $this->agentGoalsSwitch->switchGoals(false);
        self::assertFalse(AgentGoalBlueprintMCPPackage::isAvailable(), 'a bound provider is not offered while goals are off');
    }

    protected function addHostGoal(string $statement, ?int $agentSpaceId, int $priority = 0): AgentGoal
    {
        $agentGoal = AgentGoal::buildStated($statement, null, null, null, null, $priority, null);
        $agentGoal->anchorToScope(self::ACCOUNT_ID, $agentSpaceId);
        $agentGoal->markHeldByOwnerOrAgent(true);
        $agentGoal->status = $agentGoal->deriveInitialStatus();
        return $this->hostBackedAgentGoalStoreService->addHostRow($agentGoal);
    }

    protected function buildCallProvenance(): AgentToolCallProvenance
    {
        $account = new Account();
        $account->id = self::ACCOUNT_ID;
        $conversation = new AIConversation();
        $conversation->accountId = self::ACCOUNT_ID;
        $conversation->account = $account;
        $agentSpace = new AgentSpace();
        $agentSpace->id = self::AGENT_SPACE_ID;
        return new AgentToolCallProvenance(agentSpace: $agentSpace, conversation: $conversation);
    }
}
