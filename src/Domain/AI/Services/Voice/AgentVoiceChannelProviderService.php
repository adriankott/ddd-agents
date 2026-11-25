<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Voice;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Channels\AgentCallTaskTexts;
use DDD\Domain\AI\Entities\Conversations\Variants\AIConversationVariants;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallReachableChannels;
use DDD\Domain\AI\Services\Conversations\Channels\AgentVoiceChannelProvider;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;
use Throwable;

/**
 * The voice channel behind the three call questions of the agent core ({@see AgentVoiceChannelProvider}): the medium
 * of the answered call on a conversation, whether a ring reaches the owner right now (the `voiceAppRing` variant
 * dimension), and the task texts of a conversation's calls. Available while the voice channel can run in the
 * installation ({@see AgentVoiceCallAdmissionService::canVoiceRunHere()}); an application registers nothing, and it
 * keeps no subclass of the core's `AgentVoiceChannelService` beside this provider. It is the fallback of the seam: an
 * application's own provider is asked before it, and it is not counted among the providers an application registered.
 *
 * Override point of an application's subclass at the same path: {@see isCallReachReadingAskedFor()}, for an
 * application that asks its own class which dimensions it answers. The reading itself and its dimensions are those of
 * {@see AgentVoiceCallReachableChannels} (the application's subclass of it, when there is one).
 */
class AgentVoiceChannelProviderService extends Service implements AgentVoiceChannelProvider
{
    /** @var int How long one reading of what reaches the owner serves a conversation */
    public const int VOICE_CALL_REACH_PROBE_TTL_SECONDS = 120;

    /**
     * @var array<string, array{0: int, 1: AgentVoiceCallReachableChannels|null}> conversation object + id → [unix time, reading].
     * In memory only, and keyed by the conversation OBJECT, so a later job that loads the conversation again reads
     * afresh. A keyed array, not a set: an implementation detail of the service, never serialised and never returned.
     */
    protected array $voiceCallReachProbeMemoByConversationKey = [];

    /**
     * The voice channel answers while it can run in the installation
     * ({@see AgentVoiceCallAdmissionService::canVoiceRunHere()}). No request to any backend.
     */
    public function isVoiceChannelAvailable(): bool
    {
        return $this->getAgentVoiceCallAdmissionService()->canVoiceRunHere();
    }

    /**
     * The medium of the answered, not yet ended call that runs on conversation `$conversationId`, or null. The core
     * memoises this per conversation, so the conversation and its call are read once per probe.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findLiveAnsweredCallChannelType(int $conversationId): ?string
    {
        $conversation = $this->findConversation($conversationId);
        if ($conversation === null) {
            return null;
        }
        return $this->getAgentVoiceCallStoreService()->findRunningCallOfConversation($conversation)?->getChannelType();
    }

    /**
     * Puts the transient call reach dimension into `$variants` (a COPY the caller renders; the working state never
     * carries it), only while a loaded skill keys sections on it. A failed reading leaves the dimension out.
     *
     * @param string[] $loadedSkillDimensionNames
     * @return bool true when the dimension was put in
     */
    public function putCallReachVariantsInto(AIConversation $conversation, array $loadedSkillDimensionNames, AIConversationVariants $variants): bool
    {
        if (!$this->isCallReachReadingAskedFor($loadedSkillDimensionNames)) {
            return false;
        }
        $agentVoiceCallReachableChannels = $this->findVoiceCallReachableChannels($conversation);
        if ($agentVoiceCallReachableChannels === null) {
            return false;
        }
        $agentVoiceCallReachableChannels->putIntoVariants($variants);
        return true;
    }

    /**
     * Whether the conversation has calls, and the task texts of its calls (the writer classification of the admin
     * trace view).
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findCallTaskTextsOfConversation(AIConversation $conversation): AgentCallTaskTexts
    {
        $agentCallTaskTexts = new AgentCallTaskTexts();
        if (($conversation->id ?? null) === null) {
            return $agentCallTaskTexts;
        }
        $conversationVoiceCalls = $this->getAgentVoiceCallStoreService()->findCallsOfConversation($conversation);
        $agentCallTaskTexts->hasCalls = $conversationVoiceCalls->count() > 0;
        foreach ($conversationVoiceCalls->getElements() as $conversationVoiceCall) {
            if (($conversationVoiceCall->callTask ?? null) !== null && trim((string)$conversationVoiceCall->callTask) !== '') {
                $agentCallTaskTexts->taskTexts[] = trim((string)$conversationVoiceCall->callTask);
            }
        }
        return $agentCallTaskTexts;
    }

    /**
     * Whether the dimension names the loaded skills key on ask for the reading of what reaches the owner, asked of
     * the class the reading is built as (the application's subclass of it, when there is one).
     *
     * @param string[] $loadedSkillDimensionNames
     */
    protected function isCallReachReadingAskedFor(array $loadedSkillDimensionNames): bool
    {
        /** @var class-string<AgentVoiceCallReachableChannels> $reachableChannelsClassName */
        $reachableChannelsClassName = AgentVoiceCallReachableChannels::getApplicationClassNameOfValueObject(AgentVoiceCallReachableChannels::class);
        return $reachableChannelsClassName::isAskedForByDimensionNames($loadedSkillDimensionNames);
    }

    /**
     * What reaches the conversation's owner right now
     * ({@see AgentVoiceCallStartsService::findReachableChannelsForConversation()}), memoised per conversation object
     * for {@see self::VOICE_CALL_REACH_PROBE_TTL_SECONDS}: the reading asks the admission and the realtime port, and
     * the model calls of a turn must not repeat it. Null without a persisted conversation of an account, or when the
     * reading fails (logged); a failed reading serves as long as a successful one, so that a backend that does not
     * answer is not asked again at every model call.
     */
    protected function findVoiceCallReachableChannels(AIConversation $conversation): ?AgentVoiceCallReachableChannels
    {
        if (empty($conversation->id) || empty($conversation->accountId)) {
            return null;
        }
        $conversationKey = spl_object_id($conversation) . ':' . (int)$conversation->id;
        $voiceCallReachProbeMemo = $this->voiceCallReachProbeMemoByConversationKey[$conversationKey] ?? null;
        if ($voiceCallReachProbeMemo !== null && time() - $voiceCallReachProbeMemo[0] < static::VOICE_CALL_REACH_PROBE_TTL_SECONDS) {
            return $voiceCallReachProbeMemo[1];
        }
        try {
            /** @var AgentVoiceCallStartsService $agentVoiceCallStartsService */
            $agentVoiceCallStartsService = DDDService::instance()->getService(AgentVoiceCallStartsService::class);
            $agentVoiceCallReachableChannels = $agentVoiceCallStartsService->findReachableChannelsForConversation($conversation);
        } catch (Throwable $readingFailure) {
            DDDService::instance()->getLogger()->warning(
                'AIConversation ' . $conversation->id . ': call reach reading failed: ' . $readingFailure->getMessage()
            );
            $agentVoiceCallReachableChannels = null;
        }
        $this->forgetOutdatedVoiceCallReachProbes();
        $this->voiceCallReachProbeMemoByConversationKey[$conversationKey] = [time(), $agentVoiceCallReachableChannels];
        return $agentVoiceCallReachableChannels;
    }

    /**
     * Drops the readings that no longer serve ({@see self::VOICE_CALL_REACH_PROBE_TTL_SECONDS}): a worker that handles
     * turn after turn keeps only the readings of the conversations it worked on lately.
     */
    protected function forgetOutdatedVoiceCallReachProbes(): void
    {
        $nowUnixTime = time();
        foreach ($this->voiceCallReachProbeMemoByConversationKey as $conversationKey => $voiceCallReachProbeMemo) {
            if ($nowUnixTime - $voiceCallReachProbeMemo[0] >= static::VOICE_CALL_REACH_PROBE_TTL_SECONDS) {
                unset($this->voiceCallReachProbeMemoByConversationKey[$conversationKey]);
            }
        }
    }

    /**
     * The conversation the core asks about, read with the rights lifted: the question is asked inside a turn of this
     * very conversation, and the answer names only the medium of its call.
     */
    protected function findConversation(int $conversationId): ?AIConversation
    {
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $conversation = AIConversation::getService()->find($conversationId);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $conversation instanceof AIConversation ? $conversation : null;
    }

    protected function getAgentVoiceCallAdmissionService(): AgentVoiceCallAdmissionService
    {
        /** @var AgentVoiceCallAdmissionService $agentVoiceCallAdmissionService */
        $agentVoiceCallAdmissionService = DDDService::instance()->getService(AgentVoiceCallAdmissionService::class);
        return $agentVoiceCallAdmissionService;
    }

    protected function getAgentVoiceCallStoreService(): AgentVoiceCallStoreService
    {
        /** @var AgentVoiceCallStoreService $agentVoiceCallStoreService */
        $agentVoiceCallStoreService = DDDService::instance()->getService(AgentVoiceCallStoreService::class);
        return $agentVoiceCallStoreService;
    }
}
