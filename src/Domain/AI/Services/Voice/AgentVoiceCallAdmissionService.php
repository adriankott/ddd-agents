<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRightsInterface;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentRoleMappedAccountRights;
use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Entities\UsageLogs\AIUsageEnvelopeContext;
use DDD\Domain\AI\Entities\Voice\AgentVoiceAccountRights;
use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallRefusal;
use DDD\Domain\AI\Exceptions\AiBudgetExceededException;
use DDD\Domain\AI\Exceptions\AIScopeNotEligibleException;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\AIModelCallGateService;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\AI\Services\AIPromptsService;
use DDD\Domain\AI\Services\AISessionUsageService;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService;
use DDD\Domain\AI\Services\Voice\SessionWorker\AgentLiveVoiceSessionsService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DDD\Modules\Agents\AgentsModule;
use Throwable;

/**
 * Whether a call may start and whether a running call may go on. `open` and a ring ask
 * {@see findRefusalOfCallStart()} before anything costs money; the session worker asks
 * {@see findRefusalOfRunningCall()} every `AI.agentVoice.limits.admissionRecheckSeconds`. Null means yes; a refusal
 * carries the code a route answers and the end reason a running call gets.
 *
 * A call spends from its first second, and the audio goes straight to the provider, outside every path a model call
 * takes. So this check is the only thing that can stop the spend, and it is asked BEFORE a session is negotiated.
 *
 * This service is also THE one place that answers whether the voice channel can run in the installation at all
 * ({@see canVoiceRunHere()}: switched on, and the session worker's WebSocket library installed). The channel provider,
 * the tools with their agent skill, the routes and `app:agent:check-installation` ask it here.
 *
 * The default, in this order:
 * 1. {@see canVoiceRunHere()} (at the start of a call only: a process reads its config once, so a session worker
 *    never sees the switch change under a running call; a call that runs when voice is switched off goes on to its
 *    end or to the restart of its worker);
 * 2. the right {@see AgentVoiceAccountRights::USE_VOICE} (at the start of a call only: it does not change with the
 *    seconds a call runs). The package's default mapping (`AI.accountRights`) does not name it, so nobody holds it
 *    until the application grants it; a right that cannot be read is not held;
 * 3. the daily limit `AI.agentVoice.limits.dailySecondsPerAccount` against the seconds the account's calls of today
 *    booked;
 * 4. the application's model-call gate ({@see AIModelCallGateService}): the session is described as a model call
 *    ({@see buildSessionModelCall()}) and handed to the gate's stages in the order a model call runs them, with the
 *    cost of the next slice of the call as the estimate. An application without a gate of its own lets every call
 *    through.
 *
 * A RUNNING call is asked 3 and 4 again, and before them whether it has run
 * `AI.agentVoice.limits.maximumCallSeconds` since its answer ({@see isMaximumCallSecondsReached()}). Nothing else ends
 * a call at that length: the session worker waits on its socket, and no time limit of the process counts that wait.
 * The worker asks every `admissionRecheckSeconds`, so a call lasts at most that much longer, plus its goodbye.
 *
 * A gate stage that fails for another reason than a refusal (the budget cannot be read) lets the call through and is
 * logged: taking the whole channel down because the budget cannot be read would be the worse outcome. A call nobody
 * can be billed for is refused: nothing proves that a budget exists.
 *
 * Booking is not done here: the session worker books the seconds, under the model call the running call is asked
 * with ({@see buildSessionModelCallOfRunningCall()}).
 *
 * An application adds its own conditions in a subclass at the same path. The override points:
 * - {@see canVoiceRunHere()}, {@see isSessionWorkerLibraryInstalled()}: whether voice can run in the installation, and
 *   whether the session worker is part of it (an application whose calls are carried by a worker of its own answers
 *   for that worker);
 * - {@see findRefusalOfCallStart()}, {@see findRefusalOfRunningCall()}: conditions of its own before or after the
 *   default (its feature switches, an exemption an operator set);
 * - {@see isVoiceRightHeld()}, {@see findAccountRightsOfCallStart()}: how the right is answered and from which rights;
 * - {@see findBilledAccountOfConversation()}, {@see findBilledEntity()}: who pays; for a call start
 *   {@see findBilledAccountOfCallStart()} is the one place that names the account (also without a conversation), for a
 *   running call {@see buildSessionModelCallOfRunningCall()} is the one place that says so, to the gate and to the
 *   booking alike;
 * - {@see getSessionUsageScope()}, {@see getSessionEnvelopeScope()}, {@see getSessionPromptName()}: the stored names
 *   the usage is booked under, the one place they are named (the session worker reads them here);
 * - {@see getSecondsOfNextSlice()}: how far ahead the budget is asked.
 */
class AgentVoiceCallAdmissionService extends Service
{
    /** @var string The usage scope the seconds of a voice session are booked under */
    public const string USAGE_SCOPE_SESSION = 'AI.VOICE.SESSION.LIVE';

    /** @var string The scope of the usage envelope a voice session books in */
    public const string ENVELOPE_SCOPE_SESSION = 'ENVELOPE.AI.VOICE.SESSION';

    /** @var string The name of the prompt the usage of a voice session is recorded with */
    public const string PROMPT_NAME_SESSION = 'AI.VoiceCalls';

    /** @var string The provider setting that names the catalog model the seconds are priced by */
    public const string PROVIDER_SETTING_MODEL = 'model';

    /**
     * Whether the voice channel can run in this installation: it is switched on (`AI.agentVoice.enabled`) AND the
     * session worker is part of the installation ({@see isSessionWorkerLibraryInstalled()}). THE one place that
     * answers it. Asked by the first condition of a call start, by the channel provider (the channel then reads as
     * unavailable to the agent core), by the tool package (no tool, and the agent skill bound to it alone is not
     * offered) and by the routes (`403 feature_not_available`). `app:agent:check-installation` names the installation
     * in which voice is switched on and cannot run ({@see isVoiceSwitchedOnWithoutSessionWorkerLibrary()}).
     *
     * What only reads or tidies the call table asks the switch alone (`AgentVoiceConfiguration::$enabled`): the admin
     * list of calls, the ring expiry at a turn start, the stale-call sweep of `app:agent:reap` and the table condition
     * of the installation check. None of them starts or carries a call, and each goes on working in a process without
     * the library.
     */
    public function canVoiceRunHere(): bool
    {
        return AgentVoiceConfiguration::current()->enabled && $this->isSessionWorkerLibraryInstalled();
    }

    /**
     * True when voice is switched on and cannot run, because the WebSocket library of the session worker is not
     * installed: the finding `voiceChannel` of `app:agent:check-installation`.
     */
    public function isVoiceSwitchedOnWithoutSessionWorkerLibrary(): bool
    {
        return AgentVoiceConfiguration::current()->enabled && !$this->isSessionWorkerLibraryInstalled();
    }

    /**
     * Asked by `open` and before a ring. Null = the call may start.
     *
     * @param Account $account the account the call belongs to
     * @param AgentSpace|null $agentSpace the space the call works for; null = the account as a whole
     * @param AIConversation|null $conversation the conversation the call continues; null = it gets a new one
     */
    public function findRefusalOfCallStart(Account $account, ?AgentSpace $agentSpace, ?AIConversation $conversation): ?AgentVoiceCallRefusal
    {
        if (!$this->canVoiceRunHere()) {
            return AgentVoiceCallRefusal::forFeatureNotAvailable();
        }
        if (!$this->isVoiceRightHeld($account, $conversation)) {
            return AgentVoiceCallRefusal::forFeatureNotAvailable();
        }
        if ($this->isDailyLimitReached($account)) {
            return AgentVoiceCallRefusal::forDailyLimitReached();
        }
        $billedAccount = $this->findBilledAccountOfCallStart($account, $agentSpace, $conversation);

        return $this->findRefusalOfTheModelCallGate($billedAccount, $this->findBilledEntity($agentSpace, $conversation), null);
    }

    /**
     * The account a call START is billed to, the ONE place the start check names it: the model-call gate is asked for
     * this account, and the usage envelope of the question is opened for it. Default: with a conversation the account
     * of the conversation ({@see findBilledAccountOfConversation()}), else, and when that answers null, `$account`.
     *
     * An application in which the owner of the agent space pays although someone else starts the call (an agency user
     * acting for the owner, a sub-user of the owner) answers the owner here, also for a start without a conversation.
     * Who pays for a RUNNING call is said by {@see buildSessionModelCallOfRunningCall()}.
     *
     * @param Account $account the account the call belongs to
     * @param AgentSpace|null $agentSpace the space the call works for; null = the account as a whole
     * @param AIConversation|null $conversation the conversation the call continues; null = it gets a new one
     */
    protected function findBilledAccountOfCallStart(Account $account, ?AgentSpace $agentSpace, ?AIConversation $conversation): Account
    {
        if ($conversation !== null) {
            return $this->findBilledAccountOfConversation($conversation) ?? $account;
        }

        return $account;
    }

    /**
     * Whether the session worker is part of this installation: the library its WebSocket connection is made with
     * (`ratchet/pawl`, with `react/event-loop`) is installed, so the module registered the worker and its attach
     * handler as services ({@see AgentsModule::getNamespacesRequiringClasses()}). Without the library the two are no
     * services and nothing loads them.
     */
    protected function isSessionWorkerLibraryInstalled(): bool
    {
        return !AgentsModule::isClassOfAnInactivePart(AgentLiveVoiceSessionsService::class);
    }

    /**
     * Asked by the session worker while the call runs. Null = the call goes on. A refused call is told first: the
     * model says one short goodbye, then the worker hangs up with the refusal's end reason.
     *
     * In this order: the longest a call may last, the daily limit of the call's account, a call nobody can be billed
     * for, the model-call gate. The two limits come first because they need nothing but the call and its account: a
     * session model call that cannot be built (a catalog or prompt read that fails) is logged and lets the call go
     * on, as at a call start and as a gate stage that fails, and must not take the limits with it.
     */
    public function findRefusalOfRunningCall(AgentVoiceCall $agentVoiceCall): ?AgentVoiceCallRefusal
    {
        if ($this->isMaximumCallSecondsReached($agentVoiceCall)) {
            return AgentVoiceCallRefusal::forMaximumCallSecondsReached();
        }
        $account = $agentVoiceCall->accountId !== null ? $this->findAccountById((int)$agentVoiceCall->accountId) : null;
        if ($account !== null && $this->isDailyLimitReached($account)) {
            return AgentVoiceCallRefusal::forDailyLimitReached();
        }
        try {
            $sessionModelCall = $this->buildSessionModelCallOfRunningCall($agentVoiceCall);
        } catch (Throwable $gateError) {
            $this->logModelCallGateThatCouldNotBeAsked($account, $gateError);

            return null;
        }
        if ($sessionModelCall === null) {
            return AgentVoiceCallRefusal::forNoAiBudget();
        }

        return $this->findRefusalOfSessionModelCall($sessionModelCall);
    }

    /**
     * The session model call of a RUNNING call, the ONE place that says who pays for it: the account of the call's
     * conversation, else the call's own account; the billing entity of the conversation's space; the provider the
     * call runs on. The running-call check asks the gate with it and the session worker books the seconds of the call
     * with it, so an application that changes who pays changes both.
     *
     * Null = nobody can be billed for the call: the check refuses it, and the worker records no usage for it (the
     * seconds still land on the call).
     */
    public function buildSessionModelCallOfRunningCall(AgentVoiceCall $agentVoiceCall): ?AIModelCall
    {
        $conversation = $this->findConversationOfCall($agentVoiceCall);
        $billedAccount = $conversation !== null ? $this->findBilledAccountOfConversation($conversation) : null;
        if ($billedAccount === null && $agentVoiceCall->accountId !== null) {
            $billedAccount = $this->findAccountById((int)$agentVoiceCall->accountId);
        }
        if ($billedAccount === null) {
            return null;
        }

        return $this->buildSessionModelCall($billedAccount, $this->findBilledEntity(null, $conversation), $agentVoiceCall->voiceProvider);
    }

    /**
     * The voice session as the application's gate and usage recorder see it: the usage scope, the catalog model the
     * seconds are priced by, the prompt name, and who is billed. The start check and the admin list build it for an
     * account they name; a running call gets it from {@see buildSessionModelCallOfRunningCall()}.
     *
     * @param string|null $providerName the provider adapter the call runs on; null = the configured default
     */
    public function buildSessionModelCall(Account $billedAccount, ?Entity $billedEntity, ?string $providerName = null): AIModelCall
    {
        return new AIModelCall(
            argusClass: static::class,
            aiUsageScope: $this->getSessionUsageScope(),
            aiModel: $this->findSessionModel($providerName),
            aiPrompt: $this->findSessionPrompt(),
            accountBilledForAICosts: $billedAccount,
            entityWithinAccountBilledForAICosts: $billedEntity
        );
    }

    /**
     * The usage scope of a voice session. A stored value: an application returns the one its usage records carry. The
     * session worker books the seconds of a call under the same name.
     */
    public function getSessionUsageScope(): string
    {
        return static::USAGE_SCOPE_SESSION;
    }

    /** The scope of the usage envelope of a voice session. A stored value, as {@see getSessionUsageScope()}. */
    public function getSessionEnvelopeScope(): string
    {
        return static::ENVELOPE_SCOPE_SESSION;
    }

    /** The prompt name of a voice session. A stored value, as {@see getSessionUsageScope()}. */
    public function getSessionPromptName(): string
    {
        return static::PROMPT_NAME_SESSION;
    }

    /**
     * Whether `$account` holds the right to use the voice channel. A right that cannot be read is not held: when no
     * rights can be resolved ({@see findAccountRightsOfCallStart()} answers null) the call is refused, as the tool
     * executor refuses a tool that requires a right when it cannot resolve the rights of the signed-in account.
     */
    protected function isVoiceRightHeld(Account $account, ?AIConversation $conversation): bool
    {
        $accountRights = $this->findAccountRightsOfCallStart($account, $conversation);

        return $accountRights !== null && $accountRights->getMissingRightNames([AgentVoiceAccountRights::USE_VOICE]) === [];
    }

    /**
     * The rights of `$account` as the tool executor checks them, from a read that knows the application's own rights
     * model:
     * 1. `$account` is the signed-in account: the rights of the current account, the read the tool executor checks the
     *    required rights of a tool with;
     * 2. else, with a conversation of `$account`: the rights of that conversation (a worker or an operator starts a
     *    call for the owner of the conversation);
     * 3. else the rights the mapping `AI.accountRights` gives the roles of `$account`, and only in an application on
     *    the package's role-mapped rights: there this is what both reads above answer for the account. An
     *    application that answers rights with an object of its own can be asked only for the signed-in account or for a
     *    conversation, so nothing can be read for another account.
     *
     * Null = the rights cannot be read: the account of the conversation is gone, the application answered none, or
     * case 3 in an application with a rights object of its own.
     */
    protected function findAccountRightsOfCallStart(Account $account, ?AIConversation $conversation): ?AgentAccountRightsInterface
    {
        /** @var AIConversationEntitlementsService $aIConversationEntitlementsService */
        $aIConversationEntitlementsService = DDDService::instance()->getService(AIConversationEntitlementsService::class);
        if ((int)(AuthService::instance()->getAccount()?->id ?? 0) === (int)$account->id) {
            return $aIConversationEntitlementsService->findComposedAccountRightsOfCurrentAccount();
        }
        if ($conversation !== null && (int)($conversation->accountId ?? 0) === (int)$account->id) {
            return $aIConversationEntitlementsService->findComposedAccountRightsOfConversation($conversation);
        }
        if ($aIConversationEntitlementsService->isAccountRightsAnsweredByTheApplication()) {
            return null;
        }

        return AgentRoleMappedAccountRights::forAccount($account);
    }

    /**
     * Whether the calls of `$account` booked the daily limit of seconds today. The day is the server's day, as the
     * calls are stored. No limit configured = never reached.
     */
    protected function isDailyLimitReached(Account $account): bool
    {
        $dailySecondsPerAccount = AgentVoiceConfiguration::current()->dailySecondsPerAccount;
        if ($dailySecondsPerAccount === null) {
            return false;
        }
        /** @var AgentVoiceCallStoreService $agentVoiceCallStoreService */
        $agentVoiceCallStoreService = DDDService::instance()->getService(AgentVoiceCallStoreService::class);

        return $agentVoiceCallStoreService->findBookedSecondsOfAccountOnDay($account, $this->getStartOfTodayUnixTime()) >= $dailySecondsPerAccount;
    }

    /** The moment today began, as Unix time. */
    protected function getStartOfTodayUnixTime(): int
    {
        return (int)strtotime('today');
    }

    /**
     * Whether a call has run `AI.agentVoice.limits.maximumCallSeconds` since it was answered. A call that was never
     * answered has no length yet.
     */
    protected function isMaximumCallSecondsReached(AgentVoiceCall $agentVoiceCall): bool
    {
        if ($agentVoiceCall->startedAt === null) {
            return false;
        }

        return time() - $agentVoiceCall->startedAt->getTimestamp() >= AgentVoiceConfiguration::current()->maximumCallSeconds;
    }

    /**
     * The gate's answer for a call start ({@see findRefusalOfSessionModelCall()}) on the session model call of the
     * account that pays. A model call that cannot be built is logged and lets the call through, as a gate stage that
     * fails.
     */
    protected function findRefusalOfTheModelCallGate(Account $billedAccount, ?Entity $billedEntity, ?string $providerName): ?AgentVoiceCallRefusal
    {
        try {
            $sessionModelCall = $this->buildSessionModelCall($billedAccount, $billedEntity, $providerName);
        } catch (Throwable $gateError) {
            $this->logModelCallGateThatCouldNotBeAsked($billedAccount, $gateError);

            return null;
        }

        return $this->findRefusalOfSessionModelCall($sessionModelCall);
    }

    /**
     * Asks the application's model-call gate whether the session may spend, in the order a model call runs the stages:
     * what the gate requires before a model call (a usage scope, an open envelope), whether the billed account may use
     * models at all, and, where the gate checks a budget, whether the cost of the next slice fits it. A refusal by the
     * budget is `no_ai_budget`, a refusal of the billed account is `feature_not_available`. Anything else a stage
     * throws is logged and lets the call through.
     *
     * The gate requires an open usage envelope in some applications; a route asks before any is open, so one is opened
     * for the question and closed again. Inside the session worker the envelope of the session is open already.
     */
    protected function findRefusalOfSessionModelCall(AIModelCall $sessionModelCall): ?AgentVoiceCallRefusal
    {
        $billedAccount = $sessionModelCall->accountBilledForAICosts;
        $envelopeOpenedForTheQuestion = null;
        try {
            $envelopeOpenedForTheQuestion = $billedAccount !== null ? $this->openSessionEnvelopeUnlessOneIsOpen($billedAccount) : null;
            $this->askModelCallGateStages($sessionModelCall);
        } catch (AiBudgetExceededException) {
            return AgentVoiceCallRefusal::forNoAiBudget();
        } catch (AIScopeNotEligibleException) {
            return AgentVoiceCallRefusal::forFeatureNotAvailable();
        } catch (Throwable $gateError) {
            $this->logModelCallGateThatCouldNotBeAsked($billedAccount, $gateError);
        } finally {
            $this->closeEnvelopeBestEffort($envelopeOpenedForTheQuestion);
        }

        return null;
    }

    /** The log line of a gate that could not be asked: the call is let through. */
    protected function logModelCallGateThatCouldNotBeAsked(?Account $billedAccount, Throwable $gateError): void
    {
        DDDService::instance()->getLogger()->error(
            'AgentVoiceCallAdmissionService: the model-call gate could not be asked for account ' . ($billedAccount?->id ?? 'none')
            . ', the call is let through: ' . $gateError->getMessage()
        );
    }

    /**
     * The stages of the gate in the order of {@see AIModelCallGateService}. The budget stages run only where the gate
     * checks a budget. A stage refuses by throwing.
     *
     * @throws Throwable
     */
    protected function askModelCallGateStages(AIModelCall $aiModelCall): void
    {
        $aiModelCallGateService = $this->getAIModelCallGateService();
        $aiModelCallGateService->refuseModelCallWithoutUsageScopeOrEnvelope($aiModelCall);
        $aiModelCallGateService->refuseModelCallForIneligibleBilledScope($aiModelCall);
        if (!$aiModelCall->enforceBudget || !$aiModelCallGateService->needsCostEstimateForBudgetCheck($aiModelCall)) {
            return;
        }
        $aiModelCallGateService->refuseModelCallWithoutBilledAccount($aiModelCall);
        $aiModelCallGateService->loadBudgetSettingsOfBilledAccount($aiModelCall);
        $aiModelCallGateService->refuseModelCallOverBudget($aiModelCall, $this->estimateCostsOfNextSlice($aiModelCall));
    }

    /**
     * What the next slice of the call costs: its seconds at the catalog price of the session's model. Zero when the
     * catalog has no price per second for the model: the gate then decides on what was spent so far.
     */
    protected function estimateCostsOfNextSlice(AIModelCall $aiModelCall): MoneyAmount
    {
        /** @var AISessionUsageService $aISessionUsageService */
        $aISessionUsageService = DDDService::instance()->getService(AISessionUsageService::class);

        return $aISessionUsageService->calculateCostsOfSessionSeconds($aiModelCall, (float)$this->getSecondsOfNextSlice())
            ?? new MoneyAmount(0.0);
    }

    /**
     * The seconds a call runs until it is asked again: the longer of the worker's recheck interval and its smallest
     * booking slice.
     */
    protected function getSecondsOfNextSlice(): int
    {
        $agentVoiceConfiguration = AgentVoiceConfiguration::current();

        return max($agentVoiceConfiguration->admissionRecheckSeconds, $agentVoiceConfiguration->usageBookingMinimumSeconds);
    }

    /**
     * The account billed for a call on `$conversation`: the account of the conversation. Null when it has none or it
     * cannot be read; the callers then bill the account of the call.
     */
    protected function findBilledAccountOfConversation(AIConversation $conversation): ?Account
    {
        return ($conversation->accountId ?? null) !== null ? $this->findAccountById((int)$conversation->accountId) : null;
    }

    /**
     * The entity within the billed account the costs are attributed to: the billing entity of the conversation's
     * space, else of `$agentSpace`. Null = the account alone. A failed read is "none": it never refuses a call.
     */
    protected function findBilledEntity(?AgentSpace $agentSpace, ?AIConversation $conversation): ?Entity
    {
        try {
            if ($conversation !== null) {
                /** @var AgentSpacesService $agentSpacesService */
                $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);

                return $agentSpacesService->findBillingEntityOfConversation($conversation);
            }

            return $agentSpace?->findBillingEntity();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The catalog model the seconds of the session are priced by: the setting `model` of the call's provider, the
     * catalog entry of that name, else the AUDIO entry whose `externalId` is that name (the provider's model id, e.g.
     * `gpt-live-1.1` for ddd-ai's `OPENAI.GPT_LIVE_1_1`).
     */
    protected function findSessionModel(?string $providerName): ?AIModel
    {
        $agentVoiceConfiguration = AgentVoiceConfiguration::current();
        $modelName = $agentVoiceConfiguration->findProviderSetting(
            $providerName !== null && $providerName !== '' ? $providerName : $agentVoiceConfiguration->defaultProviderName,
            static::PROVIDER_SETTING_MODEL
        );
        if (!is_string($modelName) || $modelName === '') {
            return null;
        }
        /** @var AIModelsService $aIModelsService */
        $aIModelsService = DDDService::instance()->getService(AIModelsService::class);

        return $aIModelsService->getAIModelByName($modelName) ?? $aIModelsService->getAIModelByExternalId($modelName, AIModel::TYPE_AUDIO);
    }

    /** The prompt the usage of the session is recorded with; null when the application has no prompt of that name. */
    protected function findSessionPrompt(): ?AIPrompt
    {
        /** @var AIPromptsService $aIPromptsService */
        $aIPromptsService = DDDService::instance()->getService(AIPromptsService::class);

        return $aIPromptsService->getAIPromptByName($this->getSessionPromptName());
    }

    /** Opens the session's usage envelope for `$billedAccount` when none is open; null when one was open already. */
    protected function openSessionEnvelopeUnlessOneIsOpen(Account $billedAccount): ?AIUsageEnvelopeContext
    {
        $aIUsageEnvelopesService = $this->getAIUsageEnvelopesService();
        if ($aIUsageEnvelopesService->hasActiveEnvelope()) {
            return null;
        }

        return $aIUsageEnvelopesService->openEnvelope((int)$billedAccount->id, $this->getSessionEnvelopeScope());
    }

    /** Closes the envelope this service opened for its question. Never throws: a refusal already decided must stand. */
    protected function closeEnvelopeBestEffort(?AIUsageEnvelopeContext $envelopeContext): void
    {
        if ($envelopeContext === null) {
            return;
        }
        try {
            $this->getAIUsageEnvelopesService()->closeEnvelope($envelopeContext);
        } catch (Throwable $envelopeCloseError) {
            DDDService::instance()->getLogger()->warning(
                'AgentVoiceCallAdmissionService: the usage envelope of an admission question was not closed: ' . $envelopeCloseError->getMessage()
            );
        }
    }

    /**
     * The conversation a running call is on, read without the entity rights: the session worker runs without a
     * signed-in account.
     */
    protected function findConversationOfCall(AgentVoiceCall $agentVoiceCall): ?AIConversation
    {
        if ($agentVoiceCall->aiConversationId === null) {
            return null;
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $conversation = AIConversation::getService()->find((int)$agentVoiceCall->aiConversationId);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }

        return $conversation instanceof AIConversation ? $conversation : null;
    }

    /** The account with this id, read without the entity rights (the reader is a worker or a route that named it). */
    protected function findAccountById(int $accountId): ?Account
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $account = Account::getService()->find($accountId);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }

        return $account instanceof Account ? $account : null;
    }

    protected function getAIModelCallGateService(): AIModelCallGateService
    {
        /** @var AIModelCallGateService $aIModelCallGateService */
        $aIModelCallGateService = DDDService::instance()->getService(AIModelCallGateService::class);

        return $aIModelCallGateService;
    }

    protected function getAIUsageEnvelopesService(): AIUsageEnvelopesService
    {
        /** @var AIUsageEnvelopesService $aIUsageEnvelopesService */
        $aIUsageEnvelopesService = DDDService::instance()->getService(AIUsageEnvelopesService::class);

        return $aIUsageEnvelopesService;
    }
}
