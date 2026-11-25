<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Voice;

use DDD\Domain\AI\Entities\ModelCalls\AIModelCall;
use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\AI\Services\AISessionUsageService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Api\Admin\Voice\Dtos\AdminAgentVoiceCallDerivedValues;
use DDD\Presentation\Api\Admin\Voice\Dtos\AdminAgentVoiceCallsDerivedValues;
use DDD\Presentation\Api\Admin\Voice\Dtos\AdminAgentVoiceCallGetRequestDto;
use DDD\Presentation\Api\Admin\Voice\Dtos\AdminAgentVoiceCallGetResponseDto;
use DDD\Presentation\Api\Admin\Voice\Dtos\AdminAgentVoiceCallsGetRequestDto;
use DDD\Presentation\Api\Admin\Voice\Dtos\AdminAgentVoiceCallsGetResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;
use Throwable;

/**
 * The call endpoints of an admin API: the list of voice calls (query options: account, date range, booked seconds, end
 * reason) and one call with its state, its duration, its booked seconds and their costs, its end reason and the
 * conversation that holds its transcript (the conversation's id; a screen opens the conversation by it). An application
 * mounts them with one controller of its own, which sets the path prefix, the tag and the access:
 *
 *     #[Route('/ai/voiceCalls')]
 *     #[Tag(group: 'AI', name: 'AgentVoiceCalls', description: 'The voice calls of the agent')]
 *     class AdminAgentVoiceCallsController extends AdminController
 *     {
 *         use AdminAgentVoiceCallsTrait;
 *
 *         protected function findAccountIdsOfListableVoiceCalls(Account $account): ?array { … null for the oversight role … }
 *     }
 *
 * WHICH calls an account reads is decided on the server, by {@see self::findAccountIdsOfListableVoiceCalls()}: the
 * account ids are a filter of the store's query, for the list and for the detail alike, so no query option and no call
 * id reaches a call outside them. The default is restrictive: the signed-in account's own calls.
 *
 * While the voice channel is switched off (`AI.agentVoice.enabled`) both routes answer `403 feature_not_available`:
 * the call table is not required then.
 *
 * FILTER AND ORDER. The list is filtered and sorted through the query options of the call set, among them the account
 * (`accountId`), the date the call was answered (`startedAt`; `created` for calls nobody answered), the booked seconds
 * (`bookedSeconds`) and the end reason (`endReason`). The COSTS of a call are no column: they are filtered and sorted
 * through `bookedSeconds`, which for one model orders the calls the same way. The duration is no column either.
 *
 * WHAT THE SERVER DERIVES. The detail carries, beside the call, what a screen must not work out on its own; the list
 * carries the same per call in {@see AdminAgentVoiceCallDerivedValues}, one entry per call in the order of the calls:
 * - the STATE of the call ({@see AgentVoiceCall::getCallStateAt()}: `RINGING`, `IN_PROGRESS`, `ENDED`) at the time of
 *   the answer, with one reading of the clock for all rows. The times of a call do not say it alone: a call a session
 *   worker that died left open is answered and not ended, and reads `ENDED`;
 * - the COSTS of the call's booked seconds ({@see self::findBookedCostsOfVoiceCall()}): the seconds at the catalog's
 *   present price per session second of the model the call's provider runs on, the pricing every booking of the
 *   session worker used. Computed at the read, with no query; not a read of a cost ledger.
 *
 * Override points: {@see self::findAccountIdsOfListableVoiceCalls()} (whose calls),
 * {@see self::throwForbiddenUnlessVoiceCallsAccessAllowed()} (who may use the endpoints at all),
 * {@see self::findBookedCostsOfVoiceCall()} (the costs of a call: an application with a cost ledger answers what it
 * booked for the call from there).
 *
 * A call's task text is written by the agent or the owner: a screen shows it as text only.
 */
trait AdminAgentVoiceCallsTrait
{
    /**
     * @var (AIModelCall|null)[] The session model call the calls of one provider are priced with, keyed by the provider
     * name ('' = the configured default); null = none could be built. Kept for the request at hand, so that a page
     * builds one per provider and not one per row; emptied when a route starts. A keyed array, not a set: an
     * implementation detail of the trait, never serialised and never returned.
     */
    protected array $sessionModelCallsOfVoiceProviders = [];

    /**
     * The voice calls the caller may read, newest first, narrowed, sorted and paged by the request's query options,
     * and what the server derives for each call: its state now and the costs of its booked seconds.
     *
     * @throws ForbiddenException
     */
    #[Get('/calls')]
    #[Summary('AgentVoiceCalls List')]
    public function listAgentVoiceCalls(AdminAgentVoiceCallsGetRequestDto &$requestDto): AdminAgentVoiceCallsGetResponseDto
    {
        $this->throwForbiddenUnlessVoiceCallsAccessAllowed();
        $listableAccountIds = $this->findAccountIdsOfVoiceCallsThisRequestMayRead();
        $this->sessionModelCallsOfVoiceProviders = [];
        $responseDto = new AdminAgentVoiceCallsGetResponseDto();
        // The default query options are kept per class: they are set for this one read and put back after it.
        AgentVoiceCalls::setDefaultQueryOptionsSnapshot();
        try {
            AgentVoiceCalls::applyQueryOptionsFromRequestDto($requestDto);
            $responseDto->agentVoiceCalls = $this->getVoiceCallStoreOfAdminEndpoints()->findCallsOfAccountIds($listableAccountIds);
        } finally {
            AgentVoiceCalls::restoreDefaultQueryOptionsSnapshot();
        }
        // One entry per call, in the order of the calls, after the read (the class's own query options are back). The
        // clock is read once: every row of the answer is judged at the same moment.
        $nowUnixTime = time();
        $responseDto->agentVoiceCallDerivedValues = new AdminAgentVoiceCallsDerivedValues();
        foreach ($responseDto->agentVoiceCalls->getElements() as $agentVoiceCall) {
            $agentVoiceCallDerivedValues = AdminAgentVoiceCallDerivedValues::forCall(
                $agentVoiceCall,
                $this->findBookedCostsOfVoiceCall($agentVoiceCall),
                $nowUnixTime
            );
            $responseDto->agentVoiceCallDerivedValues->add($agentVoiceCallDerivedValues);
        }

        return $responseDto;
    }

    /**
     * One voice call, with its state now and the costs of its booked seconds. A call of an account the caller may not
     * read answers like a call that does not exist.
     *
     * @throws ForbiddenException
     * @throws NotFoundException `call_not_found`
     */
    #[Get('/calls/{callId}', requirements: ['callId' => '\d+'])]
    #[Summary('AgentVoiceCall Details')]
    public function getAgentVoiceCall(AdminAgentVoiceCallGetRequestDto $requestDto): AdminAgentVoiceCallGetResponseDto
    {
        $this->throwForbiddenUnlessVoiceCallsAccessAllowed();
        $agentVoiceCall = $this->getVoiceCallStoreOfAdminEndpoints()->findCallOfAccountIds(
            $requestDto->callId,
            $this->findAccountIdsOfVoiceCallsThisRequestMayRead()
        );
        if (!$agentVoiceCall instanceof AgentVoiceCall) {
            throw new NotFoundException('call_not_found');
        }
        $this->sessionModelCallsOfVoiceProviders = [];

        return AdminAgentVoiceCallGetResponseDto::forCall($agentVoiceCall, $this->findBookedCostsOfVoiceCall($agentVoiceCall), time());
    }

    /**
     * The ids of the accounts whose calls `$account` may list and open; null = the calls of every account.
     *
     * The default answers the account's own id only. An application with an oversight role answers null for it, or the
     * ids of the accounts that role looks after. The answer is a filter of the store's query, so it holds for the list
     * and for the detail, whatever the request asks for.
     *
     * @return int[]|null null = every account; an empty list = no call
     */
    protected function findAccountIdsOfListableVoiceCalls(Account $account): ?array
    {
        return [(int)$account->id];
    }

    /**
     * The costs of the seconds `$agentVoiceCall` booked: {@see AgentVoiceCall::$bookedSeconds} priced by
     * {@see AISessionUsageService::calculateCostsOfSessionSeconds()} for the session model call of the call's own
     * provider ({@see AgentVoiceCallAdmissionService::buildSessionModelCall()}), which is the method and the model call
     * every booking of the session worker is priced with. So: the booked seconds at the catalog's PRESENT price per
     * session second. Null when the catalog holds no price for the model, or when the call booked nothing.
     *
     * The one place the list and the detail get the costs of a call from. An application with a cost ledger overrides
     * it and answers what it booked for the call.
     */
    protected function findBookedCostsOfVoiceCall(AgentVoiceCall $agentVoiceCall): ?MoneyAmount
    {
        if ($agentVoiceCall->bookedSeconds <= 0) {
            return null;
        }
        $sessionModelCall = $this->getSessionModelCallOfVoiceProvider($agentVoiceCall->voiceProvider);
        if (!$sessionModelCall instanceof AIModelCall) {
            return null;
        }

        return $this->getSessionUsageServiceOfAdminEndpoints()->calculateCostsOfSessionSeconds(
            $sessionModelCall,
            (float)$agentVoiceCall->bookedSeconds
        );
    }

    /**
     * The session model call the calls of the provider `$voiceProviderName` are priced with (null or '' = the
     * configured default), built once per provider for the request at hand: building one finds the catalog model and
     * the prompt of the session. It is priced and never booked; the account in it is the signed-in account, which the
     * price does not depend on. Null without a signed-in account, and null when the model call cannot be built (a
     * provider name of an old call that no adapter answers to any more, a catalog or prompt read that fails): the
     * calls of that provider are listed without costs, the failure is logged once per request.
     */
    protected function getSessionModelCallOfVoiceProvider(?string $voiceProviderName): ?AIModelCall
    {
        $providerKey = (string)$voiceProviderName;
        if (!array_key_exists($providerKey, $this->sessionModelCallsOfVoiceProviders)) {
            $signedInAccount = AuthService::instance()->getAccount();
            $sessionModelCall = null;
            if ($signedInAccount instanceof Account) {
                try {
                    $sessionModelCall = $this->getVoiceCallAdmissionServiceOfAdminEndpoints()
                        ->buildSessionModelCall($signedInAccount, null, $voiceProviderName);
                } catch (Throwable $modelCallFailure) {
                    DDDService::instance()->getLogger()->warning(
                        'AdminAgentVoiceCallsTrait: no session model call for the voice provider "' . $providerKey
                        . '", its calls are listed without costs: ' . $modelCallFailure->getMessage()
                    );
                }
            }
            $this->sessionModelCallsOfVoiceProviders[$providerKey] = $sessionModelCall;
        }

        return $this->sessionModelCallsOfVoiceProviders[$providerKey];
    }

    /**
     * The access hook of both call endpoints, called first. Empty by default: the application restricts the path
     * (`access_control`) and overrides this for an account predicate by throwing a {@see ForbiddenException}. Whose
     * calls a permitted account reads is the other hook ({@see self::findAccountIdsOfListableVoiceCalls()}).
     *
     * @throws ForbiddenException
     */
    protected function throwForbiddenUnlessVoiceCallsAccessAllowed(): void
    {
    }

    /**
     * The account filter of this request's reads: {@see self::findAccountIdsOfListableVoiceCalls()} for the signed-in
     * account. The one place both routes pass, so it also refuses while the voice channel is switched off.
     *
     * @return int[]|null null = every account; an empty list = no call
     * @throws ForbiddenException `feature_not_available` while the voice channel is off; without a signed-in account
     */
    protected function findAccountIdsOfVoiceCallsThisRequestMayRead(): ?array
    {
        if (!AgentVoiceConfiguration::current()->enabled) {
            throw new ForbiddenException('feature_not_available');
        }
        $signedInAccount = AuthService::instance()->getAccount();
        if (!$signedInAccount instanceof Account || !isset($signedInAccount->id)) {
            throw new ForbiddenException('No authenticated account');
        }

        return $this->findAccountIdsOfListableVoiceCalls($signedInAccount);
    }

    /** The call store the admin endpoints read (from the container, so an application's subclass answers). */
    protected function getVoiceCallStoreOfAdminEndpoints(): AgentVoiceCallStoreService
    {
        /** @var AgentVoiceCallStoreService $agentVoiceCallStoreService */
        $agentVoiceCallStoreService = DDDService::instance()->getService(AgentVoiceCallStoreService::class);

        return $agentVoiceCallStoreService;
    }

    /** The admission of the voice channel, which builds the model call a session is priced and booked with. */
    protected function getVoiceCallAdmissionServiceOfAdminEndpoints(): AgentVoiceCallAdmissionService
    {
        /** @var AgentVoiceCallAdmissionService $agentVoiceCallAdmissionService */
        $agentVoiceCallAdmissionService = DDDService::instance()->getService(AgentVoiceCallAdmissionService::class);

        return $agentVoiceCallAdmissionService;
    }

    /** The service that prices the seconds of a session by the catalog. */
    protected function getSessionUsageServiceOfAdminEndpoints(): AISessionUsageService
    {
        /** @var AISessionUsageService $aISessionUsageService */
        $aISessionUsageService = DDDService::instance()->getService(AISessionUsageService::class);

        return $aISessionUsageService;
    }
}
