<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Agents\Conversations\Controller;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequestDelivery;
use DDD\Domain\AI\Entities\ClientTools\AgentUIState;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettingsOverride;
use DDD\Domain\AI\Entities\Conversations\SignalSources\ConversationSignalSources;
use DDD\Domain\AI\Entities\Conversations\Usage\AgentAIUsageScope;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageEnvelopeContext;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\ClientTools\AgentClientPresenceService;
use DDD\Domain\AI\Services\ClientTools\AgentUIRealtimeMessageTypeProviderService;
use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeAudience;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeListenerCredential;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessageDeliveries;
use DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestsService;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\SystemPrompts\AgentBaseSystemPromptCacheService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationInjectSignalPostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationPostMessagePostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartWithMessagePostRequestDto;
use DDD\Presentation\Api\Agents\Conversations\Dtos\AIConversationStartPurposePostRequestDto;
use DDD\Presentation\Base\Dtos\RequestDto;
use Throwable;

/**
 * The override points of the agent conversation controller traits ({@see AgentConversationReadsTrait},
 * {@see AgentConversationWritesTrait}, {@see AgentConversationStartTrait}, {@see AgentSelectableModelsTrait}) and the
 * helpers they share. Every hook is a protected method with a working default; an application's controller overrides
 * the ones its policy differs in (its access gate, its admin session, its device header).
 *
 * The routed methods of the traits are a complete chat surface for an application that only `use`s them; an
 * application that needs extra attributes on a route (a quota, its own envelope scope) or its own route order redeclares
 * the routed method and calls the trait's protected body (`build…Response()`). An application that only adds a request
 * parameter keeps the route: its subclass of the request DTO (at the same path under its root namespace, or named by
 * {@see self::getRequestDtoClassOfRoutedMethod()}) is what every routed method hydrates and hands to its body.
 */
trait AgentConversationControllerHooksTrait
{
    /** @var string The usage-envelope scope of the conversation searches (a routed trait method opens it when none is open). */
    public const string AGENT_CONVERSATION_SEARCH_ENVELOPE_SCOPE = AgentAIUsageScope::ENVELOPE_AI_CONVERSATION_SEARCH;

    /**
     * The application's access gate of the chat surface (a product flag, a user right), called first by every routed
     * trait method. Throws ForbiddenException (HTTP 403, the web client's `forbidden`) to refuse. The default: open to
     * every authenticated caller — the read rights still confine every conversation to its account (an unknown or
     * foreign id is HTTP 404, the web client's `notFound`).
     */
    protected function authorizeConversationAccess(): void
    {
    }

    /**
     * The space a start request asks to work for, for the application's routed start method to hand to
     * {@see AgentConversationStartTrait::buildConversationStartedResponse()}. Null = the request names none: the
     * conversation starts at account level.
     *
     * The default reads the request's `agentSpaceId` under the caller's read rights
     * ({@see AgentSpacesService::find()}). An id the caller cannot read is refused here (ForbiddenException, HTTP
     * 403) and never becomes an account-level start. The id comes from the request, so reading it proves only that
     * the caller may see the space: whether the account may START a conversation with it is decided where the
     * conversation is created ({@see AIConversationsService::findConversationToDrive()}).
     *
     * An application that names the space by a field of its own request (the id of its own entity in the path), or
     * that has one fixed space per account, overrides this method.
     *
     * @param AIConversationStartWithMessagePostRequestDto $requestDto
     * @return AgentSpace|null null = the request names no space
     * @throws ForbiddenException when the request names a space the caller cannot read
     */
    protected function findAgentSpaceOfStartRequest(AIConversationStartWithMessagePostRequestDto $requestDto): ?AgentSpace
    {
        $requestedAgentSpaceId = $requestDto->agentSpaceId ?? null;
        if ($requestedAgentSpaceId === null) {
            return null;
        }
        return $this->findReadableAgentSpaceOfRequest($requestedAgentSpaceId);
    }

    /**
     * The space a purposed start ({@see AgentConversationPurposeStartTrait::startPurpose()}) runs in. A purpose always runs
     * in a space (its singleton is kept per space), so there is no account-level answer.
     *
     * The default reads the request's `agentSpaceId` under the caller's read rights like
     * {@see self::findAgentSpaceOfStartRequest()} and refuses a request without one (HTTP 400). An application with
     * one fixed space per account (a personal space) overrides this method and answers it when the request names none.
     *
     * @param AIConversationStartPurposePostRequestDto $requestDto
     * @return AgentSpace
     * @throws BadRequestException when the request names no space
     * @throws ForbiddenException when the request names a space the caller cannot read
     */
    protected function findAgentSpaceOfPurposeStartRequest(AIConversationStartPurposePostRequestDto $requestDto): AgentSpace
    {
        $requestedAgentSpaceId = $requestDto->agentSpaceId ?? null;
        if ($requestedAgentSpaceId === null) {
            throw new BadRequestException('A purpose runs in an agent space: the request names none (agentSpaceId).');
        }
        return $this->findReadableAgentSpaceOfRequest($requestedAgentSpaceId);
    }

    /**
     * Whether a client may name a record of `$subjectEntityClass` as the subject of a purposed start of `$purposeName`
     * ({@see AgentConversationPurposeStartTrait::startPurpose()}). The record itself is read under the caller's read rights
     * afterwards; this hook narrows which entity classes a client may name at all, so a class without read rights is
     * no probe for which ids exist. Default: no class, so a start that names a subject answers 404 until the application
     * lists the classes its purposes work on (e.g. the support ticket class for a ticket purpose); a refused class
     * answers 404 like an unknown record. A start without a subject is not affected.
     *
     * @param string $purposeName
     * @param string $subjectEntityClass fully qualified, without a leading backslash
     * @return bool
     */
    protected function isSubjectEntityClassAllowedForPurposeStart(string $purposeName, string $subjectEntityClass): bool
    {
        return false;
    }

    /**
     * The space of a request's id under the caller's read rights; an id the caller cannot read is refused
     * (ForbiddenException, HTTP 403) the same way as an unknown one.
     *
     * @param int $requestedAgentSpaceId
     * @return AgentSpace
     * @throws ForbiddenException
     */
    protected function findReadableAgentSpaceOfRequest(int $requestedAgentSpaceId): AgentSpace
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        try {
            $agentSpace = $agentSpacesService->find($requestedAgentSpaceId);
        } catch (NotFoundException) {
            // the shared service throws instead of answering null when a caller switched its `throwErrors` on: the
            // refusal is the same either way (an unknown id and one of another account are not told apart)
            $agentSpace = null;
        }
        if (!$agentSpace instanceof AgentSpace) {
            // the text of the resolved service: an application words the refusal its own way in its subclass
            throw new ForbiddenException($agentSpacesService::NO_ACCESS_TO_AGENT_SPACE_MESSAGE);
        }
        return $agentSpace;
    }

    /**
     * Override point: the request DTO class the routed method `$routedMethodName` (`postMessage`, `injectSignal`, …)
     * reads its request into. The default: the application's class of the same path as the module's DTO, else the
     * module's ({@see self::getClassNameTheApplicationUsesFor()}). An application that adds a request parameter
     * declares a subclass of the module's DTO with the parameter and keeps the route; the route's documentation (the
     * generated API document and client) still names the module's DTO, so a parameter the API document must show
     * belongs on a redeclared route.
     *
     * @param string $routedMethodName the name of the trait's routed method
     * @param class-string<RequestDto> $moduleRequestDtoClassName the DTO class the routed method declares
     * @return class-string<RequestDto> the module's class or a subclass of it
     */
    protected function getRequestDtoClassOfRoutedMethod(string $routedMethodName, string $moduleRequestDtoClassName): string
    {
        return $this->getClassNameTheApplicationUsesFor($moduleRequestDtoClassName);
    }

    /**
     * The request of a routed method in the class {@see self::getRequestDtoClassOfRoutedMethod()} names: `$requestDto`
     * itself when that is its class, else a DTO of that class read from the main request (hydrated and validated like
     * every request DTO; an invalid request is HTTP 400). Called first by every routed method of the traits.
     *
     * @template T of RequestDto
     * @param string $routedMethodName
     * @param T $requestDto the DTO the framework resolved from the declared type
     * @return T
     * @throws InternalErrorException when the named class does not extend the declared one
     * @throws BadRequestException when the request is invalid for the named class
     */
    protected function resolveRequestDtoOfRoutedMethod(string $routedMethodName, RequestDto $requestDto): RequestDto
    {
        $requestDtoClassName = $this->getRequestDtoClassOfRoutedMethod($routedMethodName, $requestDto::class);
        if ($requestDtoClassName === $requestDto::class) {
            return $requestDto;
        }
        if (!is_a($requestDtoClassName, $requestDto::class, true)) {
            throw new InternalErrorException("The request DTO of {$routedMethodName}() must extend " . $requestDto::class . ", got {$requestDtoClassName}.");
        }
        $request = DDDService::instance()->getRequestService()?->getRequestStack()?->getMainRequest();
        if ($request === null) {
            return $requestDto;
        }
        $applicationRequestDto = new $requestDtoClassName();
        $applicationRequestDto->setPropertiesFromRequest($request);
        return $applicationRequestDto;
    }

    /**
     * Override point: the conversation an injected signal goes into ({@see AgentConversationWritesTrait::injectSignal()}).
     * The default: the request's conversation under the caller's read rights and customer visibility
     * ({@see self::findVisibleConversation()}). An application whose signals reach a conversation another way (a
     * conversation it keeps per screen, a narrower visibility for signals) overrides this method alone.
     *
     * @throws NotFoundException for an unknown, foreign or hidden id when the service throws errors
     */
    protected function findConversationOfInjectedSignal(
        AIConversationInjectSignalPostRequestDto $requestDto,
        AIConversationsService $aIConversationsService
    ): AIConversation {
        return $this->findVisibleConversation($aIConversationsService, (int)$requestDto->aiConversationId);
    }

    /**
     * Override point: whether a conversation the start request creates stores the full sent payload and the raw model
     * response of every model call (`RunSettings::$storeRequestSnapshot`; read with `app:agent:inspect --snapshots`).
     * The default: the request's `storeRequestSnapshot`, honoured only in an admin session ({@see self::isAdminSession()}).
     * An application that records a debug session of its own answers true for it.
     */
    protected function isRequestSnapshotStoredForStartRequest(AIConversationStartWithMessagePostRequestDto $requestDto): bool
    {
        return ($requestDto->storeRequestSnapshot ?? false) === true && $this->isAdminSession();
    }

    /** Whether a validated admin acts in this session (impersonation): the forensic fields stay on the wire then. */
    protected function isAdminSession(): bool
    {
        return AuthService::instance()->getAdminAccountOrNull() !== null;
    }

    /**
     * The conversation `$aiConversationId` under the caller's read rights and customer visibility; throws
     * NotFoundException for an unknown, foreign or hidden id when the service throws errors.
     *
     * @throws NotFoundException
     */
    protected function findVisibleConversation(AIConversationsService $aIConversationsService, int $aiConversationId): AIConversation
    {
        return $aIConversationsService->findVisibleToCustomer($aiConversationId);
    }

    /**
     * Hide the agent-forensic fields (the resolved prompt, model, tokens, cost, tool-call detail, delegation tree) from
     * the customer wire UNLESS a validated admin acts in this session ({@see self::isAdminSession()}, server-side —
     * NEVER a client flag). The raw task plan stays hidden too; customers get only the derived progress steps.
     */
    protected function hideAgentForensicsUnlessImpersonating(): void
    {
        if ($this->isAdminSession()) {
            return; // admin session → expose the full agentic trace (like the admin console)
        }
        $this->hidePropertiesOnEveryObjectOfClass(
            AIConversationMessage::class,
            'requestSnapshot',
            'toolCall',
            'aiModelName',
            'inputTokens',
            'outputTokens',
            'webSearchCount',
            'costInUSD',
            'delegatedConversations',
        );
        // agentWorkingState carries the raw task plan — English agent instructions; customers get only the
        // derived customerProgressSteps stepper view.
        $this->hidePropertiesOnEveryObjectOfClass(AIConversation::class, 'budgetBypass', 'runToken', 'agentWorkingState');
    }

    /**
     * The class the application uses in the place of a class of the module: its class of the same path under its own
     * root namespace (or the one its container maps), else the module's class itself.
     *
     * What the framework keeps per class — the default query options, the class-wide property hides — is read for
     * the class of the object at hand, and that is the application's class. Options set on the module's class would
     * never reach the query (the list would come back unfiltered and uncut); a hide registered there would leave the
     * property on the wire.
     *
     * @param class-string $moduleClassName
     * @return class-string
     */
    protected function getClassNameTheApplicationUsesFor(string $moduleClassName): string
    {
        return DDDService::instance()->getContainerServiceClassNameForClass($moduleClassName) ?? $moduleClassName;
    }

    /**
     * Hides `$propertyNames` on every serialized object of the module class `$moduleClassName` for the rest of the
     * request, and on every object of the class the application uses in its place
     * ({@see self::getClassNameTheApplicationUsesFor()}).
     *
     * A class-wide hide is checked against the class of the serialized object only, never against its parents: one
     * registered on the module's class alone leaves the property visible on the application's objects.
     *
     * @param class-string $moduleClassName
     */
    protected function hidePropertiesOnEveryObjectOfClass(string $moduleClassName, string ...$propertyNames): void
    {
        $moduleClassName::addStaticPropertiesToHide(true, ...$propertyNames);
        $applicationClassName = $this->getClassNameTheApplicationUsesFor($moduleClassName);
        if ($applicationClassName !== $moduleClassName) {
            $applicationClassName::addStaticPropertiesToHide(true, ...$propertyNames);
        }
    }

    /**
     * For every caller, admins included. A row that carries its conversation (bound by the read that loaded it, or
     * expanded) would serialize it once per row (~300 KB each). loadedCapabilities (~17 KB per row) is rendered only by
     * the admin inspector.
     */
    protected function hideParentConversationAndLoadedCapabilitiesOnMessageRows(AIConversationMessages $messages): void
    {
        $messages->hidePropertiesOnEveryMessage('aiConversation', 'loadedCapabilities');
    }

    /**
     * The conversation responses carry every message the action loaded (the whole history after a drive), so the same
     * per-row weight applies. isset() leaves an unloaded lazy `messages` unloaded.
     */
    protected function hideParentConversationAndLoadedCapabilitiesOnEmbeddedMessages(AIConversation $conversation): void
    {
        if (isset($conversation->messages)) {
            $this->hideParentConversationAndLoadedCapabilitiesOnMessageRows($conversation->messages);
        }
    }

    /**
     * The device the owner sends this request from: the client header ({@see OwnerDevice::CLIENT_HEADER_NAME}) first,
     * the user agent as the fallback. Read from the main request (a DDD request DTO cannot bind a dashed header name).
     * Never throws; a request without either yields the UNKNOWN device, which renders no tag.
     */
    protected function readOwnerDeviceFromRequest(): OwnerDevice
    {
        $request = DDDService::instance()->getRequestService()?->getRequestStack()?->getMainRequest();
        return OwnerDevice::fromRequestHints(
            $request?->headers->get(OwnerDevice::CLIENT_HEADER_NAME),
            $request?->headers->get('User-Agent')
        );
    }

    /** The per-conversation admin overrides a post or a start carries (null = none) — honoured only in an admin session (the drive decides). */
    protected function buildRunSettingsOverride(AIConversationPostMessagePostRequestDto|AIConversationStartWithMessagePostRequestDto $requestDto): ?RunSettingsOverride
    {
        return RunSettingsOverride::fromValues($requestDto->decisionModelSkillPreload ?? null, $requestDto->decisionModelTurnClosing ?? null, $requestDto->decisionModelOff ?? null, $requestDto->decisionModelReasoningEffort ?? null, $requestDto->decisionModelStallRecovery ?? null, $requestDto->decisionModelReplyLanguage ?? null, $requestDto->planDynamicsAskDecisionModel ?? null, $requestDto->planDynamicsModelReceivesVerdict ?? null);
    }

    /**
     * After a posted message started its turn. The default: the turn worker reads the base system prompt from the
     * cache; this request builds it after the response was sent when the current key has none (fire-and-forget).
     */
    protected function afterMessagePosted(AIConversation $conversation): void
    {
        /** @var AgentBaseSystemPromptCacheService $agentBaseSystemPromptCacheService */
        $agentBaseSystemPromptCacheService = DDDService::instance()->getService(AgentBaseSystemPromptCacheService::class);
        $agentBaseSystemPromptCacheService->queueBaseSystemPromptWarmAfterResponse($conversation);
    }

    /**
     * A client posts only the sources the frontend sends: a work order, a supervisor turn or a loop corrective posted by
     * a client would get their turn-start and work semantics.
     *
     * @throws BadRequestException
     */
    protected function assertSignalSourceIsPostedByTheClient(string $signalSource): void
    {
        if (ConversationSignalSources::tryFrom($signalSource)?->isPostedByTheClient() !== true) {
            throw new BadRequestException('Unknown or server-only signal source: ' . $signalSource);
        }
    }

    /**
     * Opens a usage envelope of `$scope` for the authenticated account when none is open — a routed trait method's
     * model calls are then attributed and budget-checked in an application without a request subscriber that opens
     * envelopes from an attribute. Null when one is open already (the subscriber's), nothing to close then.
     *
     * @throws InternalErrorException
     */
    protected function openConversationEnvelopeIfNoneIsOpen(string $scope): ?AIUsageEnvelopeContext
    {
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        if ($aIUsageEnvelopesService->hasActiveEnvelope()) {
            return null;
        }
        $authAccount = AuthService::instance()->getAccount();
        return $aIUsageEnvelopesService->openEnvelope(isset($authAccount->id) ? (int)$authAccount->id : null, $scope);
    }

    /** Closes an envelope {@see self::openConversationEnvelopeIfNoneIsOpen()} opened; null-safe (one line in a `finally`). */
    protected function closeConversationEnvelope(?AIUsageEnvelopeContext $aIUsageEnvelopeContext): void
    {
        if ($aIUsageEnvelopeContext === null) {
            return;
        }
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);
        $aIUsageEnvelopesService->closeEnvelope($aIUsageEnvelopeContext);
    }

    /**
     * Records the statement of the client that reads, sends or answers (`Agent.UI`): its UI state for the `[SIGNAL:ui]`
     * note and its presence for the client tool requests ({@see AgentClientPresenceService}, shared cache, no table).
     * Never fails the request it rides on: a missing cache, a refused write or a bad value is logged and skipped.
     */
    protected function stampUIClientOfConversation(AIConversation $conversation, ?string $clientId, ?AgentUIState $agentUIState, bool $isOwnerSend): void
    {
        if ($clientId === null || $clientId === '' || !isset($conversation->id)) {
            return;
        }
        try {
            $agentClientPresenceService = $this->getAgentClientPresenceService();
            $agentClientPresenceService->recordClientStatement(
                (int)($conversation->rootConversationId ?? $conversation->id),
                (int)($conversation->accountId ?? 0),
                $clientId,
                $agentUIState,
                $isOwnerSend
            );
        } catch (Throwable $throwable) {
            DDDService::instance()->getLogger()->warning('[AgentUI] client statement skipped', ['conversationId' => $conversation->id, 'reason' => $throwable->getMessage()]);
        }
    }

    /**
     * Records the statement of a client that repeats the account-level realtime read without showing a conversation:
     * only that a client of account `$accountId` is there
     * ({@see AgentClientPresenceService::recordClientStatementWithoutConversation()}, shared cache, no table), so the
     * realtime port counts it as listening for the account audience and a message to the account's clients (a call's
     * ring) is sent while only such a client is open. A read that states no client id states nothing. Never fails the
     * read it rides on: a missing cache, a refused write or a bad value is logged and skipped.
     */
    protected function stampUIClientOfAccount(int $accountId, ?string $clientId): void
    {
        if ($clientId === null || $clientId === '') {
            return;
        }
        try {
            $agentClientPresenceService = $this->getAgentClientPresenceService();
            $agentClientPresenceService->recordClientStatementWithoutConversation($accountId, $clientId);
        } catch (Throwable $throwable) {
            DDDService::instance()->getLogger()->warning('[AgentUI] client statement skipped', ['accountId' => $accountId, 'reason' => $throwable->getMessage()]);
        }
    }

    /** The presence of the user's clients the reads and sends of the traits state into (from the container) */
    protected function getAgentClientPresenceService(): AgentClientPresenceService
    {
        /** @var AgentClientPresenceService $agentClientPresenceService */
        $agentClientPresenceService = DDDService::instance()->getService(AgentClientPresenceService::class);
        return $agentClientPresenceService;
    }

    /**
     * The client tool request the reading client is to execute now (`Agent.UI`, from the realtime mailbox); null
     * without one. Never fails the run-state read: an error is logged and answers null.
     */
    protected function findClientToolRequestDeliveryOfClient(AIConversation $conversation, ?string $clientId): ?AgentClientToolRequestDelivery
    {
        if ($clientId === null || $clientId === '' || !isset($conversation->id)) {
            return null;
        }
        try {
            /** @var AgentClientToolRequestsService $agentClientToolRequestsService */
            $agentClientToolRequestsService = DDDService::instance()->getService(AgentClientToolRequestsService::class);
            return $agentClientToolRequestsService->findDeliveryForClient(
                (int)($conversation->rootConversationId ?? $conversation->id),
                (int)($conversation->accountId ?? 0),
                $clientId
            );
        } catch (Throwable $throwable) {
            DDDService::instance()->getLogger()->warning('[AgentUI] client tool request delivery skipped', ['conversationId' => $conversation->id, 'reason' => $throwable->getMessage()]);
            return null;
        }
    }

    /**
     * The open realtime messages of the realtime port for the reading client (the mailbox of a host without a
     * transport; the client tool requests come in their own field); empty on any error.
     */
    protected function findRealtimeMessageDeliveriesOfClient(AIConversation $conversation, ?string $clientId): AgentRealtimeMessageDeliveries
    {
        if (!isset($conversation->id) || !isset($conversation->accountId) || $conversation->accountId === null) {
            return new AgentRealtimeMessageDeliveries();
        }
        try {
            $agentRealtimeChannelService = $this->getAgentRealtimeChannelService();
            return $agentRealtimeChannelService->findDeliveriesForClient(
                AgentRealtimeAudience::forConversation((int)$conversation->accountId, (int)($conversation->rootConversationId ?? $conversation->id)),
                $clientId !== '' ? $clientId : null,
                [AgentUIRealtimeMessageTypeProviderService::MESSAGE_TYPE_CLIENT_TOOL_REQUEST]
            );
        } catch (Throwable $throwable) {
            DDDService::instance()->getLogger()->warning('[AgentRealtime] mailbox delivery skipped', ['conversationId' => $conversation->id, 'reason' => $throwable->getMessage()]);
            return new AgentRealtimeMessageDeliveries();
        }
    }

    /** The listener credential of the host's realtime transport for the reading account (null without a transport) */
    protected function findRealtimeListenerCredentialOfConversation(AIConversation $conversation): ?AgentRealtimeListenerCredential
    {
        if (!isset($conversation->accountId) || $conversation->accountId === null) {
            return null;
        }
        $agentRealtimeChannelService = $this->getAgentRealtimeChannelService();
        return $agentRealtimeChannelService->findListenerCredential((int)$conversation->accountId, (int)($conversation->rootConversationId ?? $conversation->id));
    }

    /** The realtime port the realtime reads of the traits ask (from the container) */
    protected function getAgentRealtimeChannelService(): AgentRealtimeChannelService
    {
        /** @var AgentRealtimeChannelService $agentRealtimeChannelService */
        $agentRealtimeChannelService = DDDService::instance()->getService(AgentRealtimeChannelService::class);
        return $agentRealtimeChannelService;
    }

    /**
     * The open realtime messages addressed to account `$accountId` (the account audience, not a conversation's) for the
     * reading client: the account's mailbox, expired messages left out, a message for one client only to that client;
     * the client tool requests (always of a conversation) are left out. Empty on any error.
     */
    protected function findRealtimeMessageDeliveriesOfAccountClient(int $accountId, ?string $clientId): AgentRealtimeMessageDeliveries
    {
        try {
            $agentRealtimeChannelService = $this->getAgentRealtimeChannelService();
            return $agentRealtimeChannelService->findDeliveriesForClient(
                AgentRealtimeAudience::forAccount($accountId),
                $clientId !== '' ? $clientId : null,
                [AgentUIRealtimeMessageTypeProviderService::MESSAGE_TYPE_CLIENT_TOOL_REQUEST]
            );
        } catch (Throwable $throwable) {
            DDDService::instance()->getLogger()->warning('[AgentRealtime] account mailbox delivery skipped', ['accountId' => $accountId, 'reason' => $throwable->getMessage()]);
            return new AgentRealtimeMessageDeliveries();
        }
    }

    /** The listener credential of the host's realtime transport for account `$accountId` without a conversation (null without a transport) */
    protected function findRealtimeListenerCredentialOfAccount(int $accountId): ?AgentRealtimeListenerCredential
    {
        $agentRealtimeChannelService = $this->getAgentRealtimeChannelService();
        return $agentRealtimeChannelService->findListenerCredential($accountId, null);
    }

    /** {@see stampUIClientOfConversation()} for a send that names its conversation by id, read under the caller's rights */
    protected function stampUIClientOfConversationId(?int $conversationId, ?string $clientId, ?AgentUIState $agentUIState): void
    {
        if ($conversationId === null || $clientId === null || $clientId === '') {
            return;
        }
        try {
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
            $conversation = $aIConversationsService->find($conversationId);
        } catch (Throwable $throwable) {
            return;
        }
        if ($conversation instanceof AIConversation) {
            $this->stampUIClientOfConversation($conversation, $clientId, $agentUIState, true);
        }
    }
}
