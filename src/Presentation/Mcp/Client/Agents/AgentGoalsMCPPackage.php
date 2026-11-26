<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolOwnerFacingSubject;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolParallelSafe;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsTrustedContent;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\Goals\AgentGoalsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentGoalAddRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentGoalsGetRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentGoalsMcpResponseDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentGoalUpdateStatusRequestDto;

/**
 * The tools of the goal register: read the goals, add one, move one through its lifecycle. Available only while goals
 * are switched on ({@see AgentGoalsService::isGoalsEnabled()}); otherwise the skill binding leaves the package out and a
 * direct call is refused. While the goal store cannot read or write (the package's table does not exist yet) every tool
 * completes with "not available" and nothing is read or written.
 *
 * The account is the one the call acts for (the owner of the conversation, else the signed-in account), never an
 * argument. The space is the conversation's, or the one `agentSpaceId` names (its use by the account checked); without
 * one a goal belongs to the account. Every write goes through {@see AgentGoalsService::persistGoal()} or
 * {@see AgentGoalsService::transitionGoalStatus()}.
 *
 * Override point of an application (a same-path subclass): {@see findReasonToRefuseGoal()}, asked before `addGoal`
 * writes.
 */
#[MCPToolReturnsTrustedContent('the goal rows of the register as the platform stores them')]
#[MCPRequiresAccountRights(AgentAccountRights::USE_AGENT_GOALS)]
class AgentGoalsMCPPackage extends MCPPackage
{
    public static ?string $name = 'agent.goals';

    public static ?string $description = 'Read and keep the owner\'s goal register: what the owner wants to reach (vision, long-term, short-term), the goals the assistant pursues in their service, and each goal\'s status over time.';

    /** @var string The refusal of a direct call while goals are switched off */
    public const string GOALS_OFF_MESSAGE = 'The goal register is not switched on in this installation: no goal can be read or saved.';

    /** @var string The owner-facing name of what the goal writes save */
    public const string OWNER_FACING_SUBJECT = 'Goals';

    public static function isAvailable(): bool
    {
        return AgentGoalsService::isGoalsEnabled();
    }

    /**
     * The goal register: the owner's goals and the goals you pursue for them, one line each with `#<id>`,
     * holder/horizon/status/scope, the statement and what else is set. Read it before the first addGoal or
     * updateGoalStatus of a conversation (the `#<id>`, no duplicate) and to answer what the owner's goals are. Not again
     * after your own write: addGoal and updateGoalStatus return the written goal.
     *
     * @example {}
     * @example {"includeFinished": true}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('one read of the goal register')]
    #[MCPToolSummary('List the owner\'s current goals and your commitments with their #ids and status. Once before the first addGoal/updateGoalStatus of a conversation, and to answer what the owner\'s goals are.')]
    public function getGoals(
        AgentGoalsGetRequestDto $requestDto,
        AgentGoalsService $agentGoalsService,
        AgentToolCallProvenance $callProvenance
    ): AgentGoalsMcpResponseDto {
        $this->requireGoalsEnabled();
        if (!$agentGoalsService->isGoalStoreAvailable()) {
            return AgentGoalsMcpResponseDto::forRegisterNotAvailable(false);
        }
        $actingAccount = $this->requireActingAccount($callProvenance);
        $agentSpace = $this->findAgentSpaceOfCall($requestDto->agentSpaceId, $callProvenance);
        return AgentGoalsMcpResponseDto::forGoals($agentGoalsService->findCurrentGoalsForScope(
            (int)$actingAccount->id,
            $agentSpace !== null ? (int)$agentSpace->id : null,
            !($requestDto->includeFinished ?? false)
        ));
    }

    /**
     * Saves ONE goal the moment the owner states it: one neutral sentence of the state they want (`statement`), the
     * horizon only when their words carry one (VISION never completes). One call per goal, never collected at the end.
     * An existing goal is not added again (the register returns it). With `statedByOwner` false the goal is your
     * proposal, stored as PROPOSED until the owner confirms it.
     *
     * @example {"statement": "The bakery is fully booked for weekend catering through the summer.", "horizonLevel": "LONG_TERM", "reason": "Weekdays are full, weekends are quiet"}
     * @example {"statement": "The new seasonal menu is online.", "horizonLevel": "SHORT_TERM", "targetDate": "2027-04-30"}
     * @example {"statement": "Every new review gets an answer within two days.", "statedByOwner": false, "parentGoalId": 12}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    #[MCPToolOwnerFacingSubject(self::OWNER_FACING_SUBJECT)]
    #[MCPToolSummary('Save ONE goal the owner just stated (one neutral sentence of the state wanted; a horizon only when their words carry one), or propose your own for confirmation (statedByOwner false). Returns the written goal with its #id.')]
    public function addGoal(
        AgentGoalAddRequestDto $requestDto,
        AgentGoalsService $agentGoalsService,
        AgentToolCallProvenance $callProvenance
    ): AgentGoalsMcpResponseDto {
        $this->requireGoalsEnabled();
        if (!$agentGoalsService->isGoalStoreAvailable()) {
            return AgentGoalsMcpResponseDto::forRegisterNotAvailable(true);
        }
        $actingAccount = $this->requireActingAccount($callProvenance);
        if (trim($requestDto->statement) === '') {
            throw new BadRequestException(AgentGoalsService::STATEMENT_MISSING_MESSAGE);
        }
        $agentGoal = AgentGoal::buildStated(
            $requestDto->statement,
            $requestDto->toHorizonLevel(),
            $requestDto->reason,
            $requestDto->successSignal,
            $requestDto->toTargetDate(),
            $requestDto->priority ?? 0,
            $requestDto->parentGoalId
        );
        $agentSpace = $this->findAgentSpaceOfCall($requestDto->agentSpaceId, $callProvenance);
        $agentGoal->anchorToScope((int)$actingAccount->id, $agentSpace !== null ? (int)$agentSpace->id : null);
        $agentGoal->markHeldByOwnerOrAgent($requestDto->isStatedByOwner());
        $agentGoal->blueprintKey = $requestDto->toBlueprintKey();
        $agentGoal->sourceMessageId = $callProvenance->getSourceUserMessageId();
        $refusalReason = $this->findReasonToRefuseGoal($agentGoal, $requestDto, $callProvenance);
        if ($refusalReason !== null) {
            return AgentGoalsMcpResponseDto::forRefusedGoal($refusalReason);
        }
        $persistedGoal = $agentGoalsService->persistGoal($agentGoal);
        $wasAlreadySaved = !isset($agentGoal->id) || (int)$agentGoal->id !== (int)$persistedGoal->id;
        return AgentGoalsMcpResponseDto::forPersistedGoal($persistedGoal, $wasAlreadySaved);
    }

    /**
     * Moves ONE goal by its `#<id>`: confirm a PROPOSED goal or resume one (ACTIVE), ACHIEVED (a note on what was
     * reached), DROPPED (why), PAUSED, or BLOCKED (only a goal you hold; the note names the blocker). A VISION is never
     * achieved. To reword a goal, add the new wording with addGoal: it replaces the old version.
     *
     * @example {"goalId": 12, "status": "ACTIVE"}
     * @example {"goalId": 12, "status": "ACHIEVED", "statusNote": "Every weekend in June is booked."}
     * @example {"goalId": 15, "status": "BLOCKED", "statusNote": "The calendar connection lapsed; the owner has to reconnect it.", "requiresUserAction": true}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    #[MCPToolOwnerFacingSubject(self::OWNER_FACING_SUBJECT)]
    #[MCPToolSummary('Change ONE goal\'s status by #id (confirm, achieve, drop, pause, block) with a one-line note. Take the #id from getGoals.')]
    public function updateGoalStatus(
        AgentGoalUpdateStatusRequestDto $requestDto,
        AgentGoalsService $agentGoalsService,
        AgentToolCallProvenance $callProvenance
    ): AgentGoalsMcpResponseDto {
        $this->requireGoalsEnabled();
        if (!$agentGoalsService->isGoalStoreAvailable()) {
            return AgentGoalsMcpResponseDto::forRegisterNotAvailable(true);
        }
        $actingAccount = $this->requireActingAccount($callProvenance);
        $agentGoal = $agentGoalsService->findGoal($requestDto->goalId);
        if ($agentGoal === null || (int)$agentGoal->accountId !== (int)$actingAccount->id) {
            throw new NotFoundException('No such goal — take the #<id> from getGoals.');
        }
        return AgentGoalsMcpResponseDto::forOneGoal(
            $agentGoalsService->transitionGoalStatus(
                $agentGoal,
                $requestDto->toStatus(),
                $requestDto->statusNote,
                $requestDto->requiresUserAction ?? false,
                $requestDto->retryable ?? false
            ),
            AgentGoalsMcpResponseDto::NOTE_STATUS_UPDATED
        );
    }

    /**
     * Whether the application refuses the goal `addGoal` is about to write, and why: the reason reaches the model after
     * the marker that nothing was saved, and the call completes. The default accepts every goal (null). The goal is
     * complete (scope, holder, provenance, source message); an application may also throw a refusal (a 4xx) instead,
     * for a call the model must correct.
     *
     * @param AgentGoal $agentGoal the goal about to be written
     * @param AgentGoalAddRequestDto $requestDto the call's arguments
     * @param AgentToolCallProvenance $callProvenance where the call runs
     * @return string|null The reason, or null to accept
     */
    protected function findReasonToRefuseGoal(AgentGoal $agentGoal, AgentGoalAddRequestDto $requestDto, AgentToolCallProvenance $callProvenance): ?string
    {
        return null;
    }

    /**
     * Refuses the call while goals are switched off.
     *
     * @return void
     * @throws BadRequestException
     */
    protected function requireGoalsEnabled(): void
    {
        if (!static::isAvailable()) {
            throw new BadRequestException(static::GOALS_OFF_MESSAGE);
        }
    }

    /**
     * The account the call acts for: the owner of the conversation, else the signed-in account.
     *
     * @param AgentToolCallProvenance $callProvenance
     * @return Account
     * @throws ForbiddenException
     */
    protected function requireActingAccount(AgentToolCallProvenance $callProvenance): Account
    {
        $conversation = $callProvenance->conversation;
        $actingAccount = ($conversation !== null ? ($conversation->account ?? null) : null) ?? AuthService::instance()->getAccount();
        if (!$actingAccount instanceof Account || !isset($actingAccount->id)) {
            throw new ForbiddenException('No authenticated account');
        }
        return $actingAccount;
    }

    /**
     * The space the call names (its use by the account checked), else the conversation's space; null at account level.
     *
     * @param int|null $agentSpaceId
     * @param AgentToolCallProvenance $callProvenance
     * @return AgentSpace|null
     */
    protected function findAgentSpaceOfCall(?int $agentSpaceId, AgentToolCallProvenance $callProvenance): ?AgentSpace
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $agentSpace = $agentSpacesService->findAgentSpaceOfToolCall([AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => $agentSpaceId], $callProvenance);
        return $agentSpace instanceof AgentSpace && isset($agentSpace->id) ? $agentSpace : null;
    }
}
