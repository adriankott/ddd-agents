<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\ClientTools;

use DDD\Domain\AI\Entities\ClientTools\AgentClientPresence;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolAnswer;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequestDelivery;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequestType;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequestTypes;
use DDD\Domain\AI\Entities\ClientTools\AgentScreenPicture;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeAudience;
use DDD\Domain\AI\Entities\Conversations\Realtime\AgentRealtimeMessage;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService;
use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService;
use DDD\Domain\AI\Services\Eval\AgentScriptedUIClientService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\RasterImageDecoder;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/**
 * The channel between a tool on a worker and the user's client (`Agent.UI`), without a table:
 *
 * 1. a tool asks {@see requestAndWait()}: without a fresh client ({@see AgentClientPresenceService}) it answers at once;
 *    else the request is sent through the realtime port ({@see AgentRealtimeChannelService}) to one client, and the
 *    tool waits, reading the answer key of the shared cache, touching the run's heartbeat once a second;
 * 2. the client receives it through the host's transport, or from the mailbox with its run-state read
 *    ({@see findDeliveryForClient()});
 * 3. the client posts its answer; the answer route writes it to the shared cache under the request id, set if absent,
 *    with a TTL of the wait window plus a margin ({@see answerRequest()});
 * 4. the tool reads the answer, deletes it and clears the request from the mailbox: screen content never stays.
 *
 * A conversation of an evaluation account with a scripted screen is answered by {@see AgentScriptedUIClientService}
 * into the same answer key.
 */
class AgentClientToolRequestsService extends Service
{
    /** @var int How often the waiting tool reads the answer key, in microseconds */
    public const int WAIT_READ_INTERVAL_MICROSECONDS = 250000;

    /** @var int How often the waiting tool touches the run's heartbeat, in seconds */
    public const int HEARTBEAT_INTERVAL_SECONDS = 1;

    /** @var int The longest wait a tool may ask for (below the UI package's execution ceiling of 25 s) */
    public const int MAX_WAIT_SECONDS = 20;

    /** @var int Seconds the request record and the answer outlive the wait window */
    public const int ANSWER_TTL_MARGIN_SECONDS = 30;

    /** @var string The prefix of a request's record (what the answer route checks) */
    public const string REQUEST_KEY_PREFIX = 'agent-client-tool-request-';

    /** @var string The prefix of a request's answer */
    public const string ANSWER_KEY_PREFIX = 'agent-client-tool-answer-';

    /** @var string The prefix of the counter that lets exactly one answer in */
    public const string ANSWER_CLAIM_KEY_PREFIX = 'agent-client-tool-answer-claim-';

    /** @var int The longest base64 text of a screen picture (its byte cap, base64) */
    public const int MAX_PICTURE_BASE64_CHARS = 819200;

    /** @var iterable<AgentClientToolRequestTypeProvider> */
    protected iterable $agentClientToolRequestTypeProviders = [];

    protected ?AgentClientToolRequestTypes $registeredRequestTypes = null;

    /** @param iterable<AgentClientToolRequestTypeProvider> $agentClientToolRequestTypeProviders */
    #[Required]
    public function setAgentClientToolRequestTypeProviders(
        #[AutowireIterator(AgentClientToolRequestTypeProvider::TAG)]
        iterable $agentClientToolRequestTypeProviders
    ): void {
        $this->agentClientToolRequestTypeProviders = $agentClientToolRequestTypeProviders;
        $this->registeredRequestTypes = null;
    }

    /** The registered request types of every provider; a type registered twice keeps the first, an invalid name is skipped */
    public function getRegisteredRequestTypes(): AgentClientToolRequestTypes
    {
        if ($this->registeredRequestTypes !== null) {
            return $this->registeredRequestTypes;
        }
        $agentClientToolRequestTypes = new AgentClientToolRequestTypes();
        foreach ($this->agentClientToolRequestTypeProviders as $agentClientToolRequestTypeProvider) {
            try {
                $providedRequestTypes = $agentClientToolRequestTypeProvider->getClientToolRequestTypes();
            } catch (Throwable $providerFailure) {
                DDDService::instance()->getLogger()->warning('[AgentUI] client tool request type provider failed: ' . $providerFailure->getMessage());
                continue;
            }
            foreach ($providedRequestTypes->getElements() as $agentClientToolRequestType) {
                if (AgentClientToolRequestType::isValidName($agentClientToolRequestType->name)
                    && $agentClientToolRequestTypes->findRequestType($agentClientToolRequestType->name) === null
                ) {
                    $agentClientToolRequestTypes->add($agentClientToolRequestType);
                }
            }
        }
        return $this->registeredRequestTypes = $agentClientToolRequestTypes;
    }

    /**
     * Sends a request to the user's client and waits for its answer.
     *
     * @param int $conversationId the owner's conversation (the root of a delegation tree)
     * @param int $accountId the owner of the conversation (the account the tool runs for)
     * @param string $requestType a registered, switched-on type ({@see getRegisteredRequestTypes()})
     * @param array<string, mixed> $arguments flat: strings, ints, bools and lists of strings; encoded at once, at most
     *                                        AgentClientToolRequest::MAX_ARGUMENTS_JSON_CHARS as JSON
     * @param int $waitSeconds at most {@see self::MAX_WAIT_SECONDS}
     * @throws BadRequestException arguments too long
     */
    public function requestAndWait(
        int $conversationId,
        int $accountId,
        ?string $toolCallId,
        string $requestType,
        array $arguments,
        int $waitSeconds
    ): AgentClientToolAnswer {
        $argumentsJson = (string)json_encode((object)$arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (mb_strlen($argumentsJson) > AgentClientToolRequest::MAX_ARGUMENTS_JSON_CHARS) {
            throw new BadRequestException('A client tool request has at most ' . AgentClientToolRequest::MAX_ARGUMENTS_JSON_CHARS . ' characters of arguments.');
        }
        if (!$this->getRegisteredRequestTypes()->isEnabledRequestType($requestType)) {
            return AgentClientToolAnswer::ofKind(AgentClientToolAnswer::KIND_NOT_ENABLED);
        }
        $waitSeconds = max(1, min(static::MAX_WAIT_SECONDS, $waitSeconds));
        $now = new DateTime();
        $agentScriptedUIClientService = $this->getAgentScriptedUIClientService();
        $isScripted = $agentScriptedUIClientService->hasScriptedScreen($conversationId);
        $targetClientId = AgentScriptedUIClientService::SCRIPTED_CLIENT_ID;
        if (!$isScripted) {
            $targetPresence = $this->getAgentClientPresenceService()->findTargetPresence($conversationId);
            if ($targetPresence === null) {
                return AgentClientToolAnswer::ofKind(AgentClientToolAnswer::KIND_NO_CLIENT);
            }
            if ($targetPresence->visible === false) {
                // a hidden client does not read (the package's chat stops its reads while hidden): waiting is useless
                return AgentClientToolAnswer::ofKind(AgentClientToolAnswer::KIND_BACKGROUND);
            }
            $targetClientId = $targetPresence->clientId;
        }
        $sharedCache = $this->getSharedCache();
        if ($sharedCache === null) {
            return AgentClientToolAnswer::ofKind(AgentClientToolAnswer::KIND_NO_CLIENT);
        }

        $agentClientToolRequest = new AgentClientToolRequest();
        $agentClientToolRequest->id = AgentClientToolRequest::generateRequestId();
        $agentClientToolRequest->conversationId = $conversationId;
        $agentClientToolRequest->accountId = $accountId;
        $agentClientToolRequest->toolCallId = $toolCallId !== null ? mb_substr($toolCallId, 0, 64) : null;
        $agentClientToolRequest->requestType = $requestType;
        $agentClientToolRequest->argumentsJson = $argumentsJson;
        $agentClientToolRequest->targetClientId = $targetClientId;
        $agentClientToolRequest->expiresAt = (clone $now)->modify('+' . $waitSeconds . ' seconds');
        $sharedCache->set($this->buildRequestKey($agentClientToolRequest->id), $agentClientToolRequest->toCacheEntry(), $waitSeconds + static::ANSWER_TTL_MARGIN_SECONDS);

        $agentRealtimeAudience = AgentRealtimeAudience::forConversation($accountId, $conversationId);
        try {
            if ($isScripted) {
                $agentScriptedUIClientService->answerRequest($agentClientToolRequest);
            } else {
                $this->getAgentRealtimeChannelService()->sendMessage(AgentRealtimeMessage::create(
                    AgentUIRealtimeMessageTypeProviderService::MESSAGE_TYPE_CLIENT_TOOL_REQUEST,
                    (string)$agentClientToolRequest->id,
                    (string)json_encode($agentClientToolRequest->toDelivery($now)->toObject(false), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $agentClientToolRequest->expiresAt,
                    $agentRealtimeAudience,
                    $targetClientId
                ));
            }
            return $this->waitForAnswer($agentClientToolRequest, $waitSeconds, $now);
        } finally {
            $this->forgetAnswer($agentClientToolRequest->id);
            if (!$isScripted) {
                $this->getAgentRealtimeChannelService()->clearMessage(
                    $agentRealtimeAudience,
                    AgentUIRealtimeMessageTypeProviderService::MESSAGE_TYPE_CLIENT_TOOL_REQUEST,
                    (string)$agentClientToolRequest->id
                );
            }
        }
    }

    /**
     * The request the reading client is to execute now: the oldest open request of the conversation's mailbox
     * addressed to the client and not yet answered. A client executes a request id once (the npm runner remembers
     * the ids), so a request is delivered with every read until it is answered or expires.
     */
    public function findDeliveryForClient(int $conversationId, int $accountId, string $clientId): ?AgentClientToolRequestDelivery
    {
        if (!AgentClientPresence::isValidClientId($clientId)) {
            return null;
        }
        $now = new DateTime();
        $openMessages = $this->getAgentRealtimeChannelService()->findOpenMessages(
            AgentRealtimeAudience::forConversation($accountId, $conversationId),
            AgentUIRealtimeMessageTypeProviderService::MESSAGE_TYPE_CLIENT_TOOL_REQUEST
        );
        foreach ($openMessages->getElements() as $agentRealtimeMessage) {
            if (!$agentRealtimeMessage->isForClient($clientId) || $agentRealtimeMessage->targetClientId === null) {
                continue;
            }
            $agentClientToolRequest = $this->findRequest((int)$agentRealtimeMessage->messageKey);
            if ($agentClientToolRequest === null || $agentClientToolRequest->conversationId !== $conversationId || $this->hasAnswer($agentClientToolRequest->id)) {
                continue;
            }
            return $agentClientToolRequest->toDelivery($now);
        }
        return null;
    }

    /**
     * Stores the answer of the client a request was addressed to: set if absent (the first answer wins), until the
     * wait window plus a margin ends. A picture is checked as untrusted client bytes ({@see checkScreenPicture()}).
     *
     * @param string $outcome `OK` for a request carried out, else the client's refusal code
     * @param string|null $text at most AgentClientToolRequest::MAX_ANSWER_TEXT_CHARS
     * @param string|null $detailsJson one flat JSON object (strings, numbers, booleans); other entries are dropped, at
     *                                 most AgentClientToolRequest::MAX_ANSWER_DETAILS_JSON_CHARS
     * @param string|null $pictureBase64 a screen picture (base64), at most {@see MAX_PICTURE_BASE64_CHARS}
     * @throws BadRequestException a value out of its bounds, a picture that is none
     * @throws ConflictException the request no longer waits for an answer (answered, expired)
     * @throws NotFoundException no such request of this conversation for this client
     * @throws ForbiddenException the caller does not hold the right to use the UI tools
     */
    public function answerRequest(
        int $conversationId,
        int $requestId,
        string $clientId,
        string $outcome,
        ?string $text,
        ?string $detailsJson,
        ?string $pictureBase64 = null
    ): void {
        if (preg_match('/^[A-Z][A-Z_]{1,47}$/', $outcome) !== 1) {
            throw new BadRequestException('The outcome is a code of capital letters and underscores, at most ' . AgentClientToolRequest::MAX_OUTCOME_CHARS . ' characters.');
        }
        if ($text !== null && mb_strlen($text) > AgentClientToolRequest::MAX_ANSWER_TEXT_CHARS) {
            throw new BadRequestException('The answer text has at most ' . AgentClientToolRequest::MAX_ANSWER_TEXT_CHARS . ' characters.');
        }
        $detailsJson = static::keepFlatDetailsJson($detailsJson);
        if (mb_strlen($detailsJson) > AgentClientToolRequest::MAX_ANSWER_DETAILS_JSON_CHARS) {
            throw new BadRequestException('The answer details have at most ' . AgentClientToolRequest::MAX_ANSWER_DETAILS_JSON_CHARS . ' characters as JSON.');
        }
        $agentClientToolRequest = $this->findRequest($requestId);
        if ($agentClientToolRequest === null || $agentClientToolRequest->conversationId !== $conversationId || $agentClientToolRequest->targetClientId !== $clientId) {
            throw new NotFoundException('No client tool request ' . $requestId . ' for this client.');
        }
        if ($clientId !== AgentScriptedUIClientService::SCRIPTED_CLIENT_ID && !$this->isCurrentAccountAllowedToUseUI()) {
            throw new ForbiddenException('This account may not use the UI tools.');
        }
        if ($agentClientToolRequest->getSecondsLeft(new DateTime()) <= 0) {
            throw new ConflictException('Client tool request ' . $requestId . ' no longer waits for an answer.');
        }
        $agentScreenPicture = $pictureBase64 !== null && $pictureBase64 !== '' ? static::checkScreenPicture($pictureBase64) : null;
        $answerTtlSeconds = $agentClientToolRequest->getSecondsLeft(new DateTime()) + static::ANSWER_TTL_MARGIN_SECONDS;
        if (!$this->claimAnswer($requestId, $answerTtlSeconds)) {
            throw new ConflictException('Client tool request ' . $requestId . ' no longer waits for an answer.');
        }
        $this->getSharedCache()?->set($this->buildAnswerKey($requestId), [
            'outcome' => $outcome,
            'text' => $text,
            'detailsJson' => $detailsJson,
            'picture' => $agentScreenPicture?->toCacheEntry(),
        ], $answerTtlSeconds);
    }

    /**
     * A screen picture as untrusted client bytes: strict base64, at most {@see AgentScreenPicture::MAX_BYTES}, the type
     * from the magic bytes (WebP, JPEG, PNG), the size measured by Core's pinned decoder, at most
     * {@see AgentScreenPicture::MAX_PIXELS}. Never decoded beyond the probe here; the tool picture store decodes it
     * through the same decoder when it brings it to the wire profile.
     *
     * @throws BadRequestException
     */
    public static function checkScreenPicture(string $pictureBase64): AgentScreenPicture
    {
        if (strlen($pictureBase64) > static::MAX_PICTURE_BASE64_CHARS) {
            throw new BadRequestException('A screen picture has at most ' . AgentScreenPicture::MAX_BYTES . ' bytes.');
        }
        $pictureBytes = base64_decode($pictureBase64, true);
        if ($pictureBytes === false || $pictureBytes === '' || strlen($pictureBytes) > AgentScreenPicture::MAX_BYTES) {
            throw new BadRequestException('A screen picture is base64 of at most ' . AgentScreenPicture::MAX_BYTES . ' bytes.');
        }
        $magicMimeType = RasterImageDecoder::detectMimeType(substr($pictureBytes, 0, RasterImageDecoder::HEADER_BYTES));
        if ($magicMimeType === null || !in_array($magicMimeType, AgentScreenPicture::ALLOWED_MIME_TYPES, true)) {
            throw new BadRequestException('A screen picture is WebP, JPEG or PNG.');
        }
        try {
            $rasterImageInfo = RasterImageDecoder::probe($pictureBytes);
        } catch (Throwable) {
            throw new BadRequestException('The screen picture could not be read.');
        }
        if ($rasterImageInfo->mimeType !== $magicMimeType || $rasterImageInfo->width <= 0 || $rasterImageInfo->height <= 0
            || $rasterImageInfo->width * $rasterImageInfo->height > AgentScreenPicture::MAX_PIXELS
        ) {
            throw new BadRequestException('A screen picture has at most ' . AgentScreenPicture::MAX_PIXELS . ' pixels.');
        }
        $agentScreenPicture = new AgentScreenPicture();
        $agentScreenPicture->base64Data = base64_encode($pictureBytes);
        $agentScreenPicture->mimeType = $magicMimeType;
        $agentScreenPicture->width = $rasterImageInfo->width;
        $agentScreenPicture->height = $rasterImageInfo->height;
        $agentScreenPicture->byteSize = strlen($pictureBytes);
        return $agentScreenPicture;
    }

    /** The record of a sent request, while its wait window and margin last; null otherwise */
    public function findRequest(int $requestId): ?AgentClientToolRequest
    {
        try {
            return AgentClientToolRequest::fromCacheEntry($this->getSharedCache()?->get($this->buildRequestKey($requestId)));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Waits until the answer key holds an answer or the wait is over (then one last read: an answer that came at the
     * last moment still counts).
     */
    protected function waitForAnswer(AgentClientToolRequest $agentClientToolRequest, int $waitSeconds, DateTime $startedAt): AgentClientToolAnswer
    {
        $deadline = microtime(true) + $waitSeconds;
        $nextHeartbeatAt = 0.0;
        while (true) {
            $agentClientToolAnswer = $this->readAnswer($agentClientToolRequest->id, $startedAt);
            if ($agentClientToolAnswer !== null) {
                return $agentClientToolAnswer;
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            if (microtime(true) >= $nextHeartbeatAt) {
                $this->touchRunHeartbeat($agentClientToolRequest->conversationId);
                $nextHeartbeatAt = microtime(true) + static::HEARTBEAT_INTERVAL_SECONDS;
            }
            $this->pauseBetweenReads();
        }
        $agentClientToolAnswer = $this->readAnswer($agentClientToolRequest->id, $startedAt);
        if ($agentClientToolAnswer !== null) {
            return $agentClientToolAnswer;
        }
        $agentClientToolAnswer = AgentClientToolAnswer::ofKind(AgentClientToolAnswer::KIND_NO_ANSWER);
        $agentClientToolAnswer->requestId = $agentClientToolRequest->id;
        $agentClientToolAnswer->outcome = AgentClientToolRequest::OUTCOME_NO_ANSWER;
        $agentClientToolAnswer->secondsWaited = (new DateTime())->getTimestamp() - $startedAt->getTimestamp();
        return $agentClientToolAnswer;
    }

    /** The answer of a request when one is stored; null otherwise */
    protected function readAnswer(int $requestId, DateTime $startedAt): ?AgentClientToolAnswer
    {
        try {
            $storedAnswer = $this->getSharedCache()?->get($this->buildAnswerKey($requestId));
        } catch (Throwable) {
            return null;
        }
        if (!is_array($storedAnswer) || !is_string($storedAnswer['outcome'] ?? null)) {
            return null;
        }
        $agentClientToolAnswer = AgentClientToolAnswer::ofKind(
            $storedAnswer['outcome'] === AgentClientToolRequest::OUTCOME_OK ? AgentClientToolAnswer::KIND_ANSWERED : AgentClientToolAnswer::KIND_REFUSED
        );
        $agentClientToolAnswer->requestId = $requestId;
        $agentClientToolAnswer->outcome = $storedAnswer['outcome'];
        $agentClientToolAnswer->text = is_string($storedAnswer['text'] ?? null) ? $storedAnswer['text'] : null;
        $agentClientToolAnswer->detailsJson = static::keepFlatDetailsJson(is_string($storedAnswer['detailsJson'] ?? null) ? $storedAnswer['detailsJson'] : null);
        $agentClientToolAnswer->picture = AgentScreenPicture::fromCacheEntry($storedAnswer['picture'] ?? null);
        $agentClientToolAnswer->secondsWaited = (new DateTime())->getTimestamp() - $startedAt->getTimestamp();
        return $agentClientToolAnswer;
    }

    protected function hasAnswer(int $requestId): bool
    {
        try {
            return $this->getSharedCache()?->get($this->buildAnswerKey($requestId)) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Lets exactly one answer in: an atomic counter where the cache has one (APCu, Redis), else a read before the
     * write (two answers in the same instant may then both pass; the later one wins).
     */
    protected function claimAnswer(int $requestId, int $ttlSeconds): bool
    {
        $sharedCache = $this->getSharedCache();
        if ($sharedCache === null) {
            return false;
        }
        try {
            return $sharedCache->increment(static::ANSWER_CLAIM_KEY_PREFIX . $requestId, 1, $ttlSeconds) === 1;
        } catch (\BadMethodCallException) {
            return $sharedCache->get($this->buildAnswerKey($requestId)) === null;
        }
    }

    /** Removes the answer of a request: what a screen showed never stays once the tool read it (or stopped waiting) */
    protected function forgetAnswer(int $requestId): void
    {
        try {
            $this->getSharedCache()?->delete($this->buildAnswerKey($requestId));
        } catch (Throwable) {
            // the answer expires with its TTL
        }
    }

    /** Whether the calling account holds the right to use the UI tools ({@see AgentAccountRights::USE_UI}; no rights composed = ungated) */
    protected function isCurrentAccountAllowedToUseUI(): bool
    {
        /** @var AIConversationEntitlementsService $aiConversationEntitlementsService */
        $aiConversationEntitlementsService = DDDService::instance()->getService(AIConversationEntitlementsService::class);
        $accountRights = $aiConversationEntitlementsService->findComposedAccountRightsOfCurrentAccount();
        return $accountRights === null || $accountRights->getMissingRightNames([AgentAccountRights::USE_UI]) === [];
    }

    /** Keeps the run alive in the reaper's eyes while the tool waits (best effort, as the module documents it) */
    protected function touchRunHeartbeat(int $conversationId): void
    {
        /** @var AIConversationRunClaimsService $aiConversationRunClaimsService */
        $aiConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        $aiConversationRunClaimsService->touchRunningHeartbeat($conversationId);
    }

    /** The pause between two reads of the answer key (overridable for a test) */
    protected function pauseBetweenReads(): void
    {
        usleep(static::WAIT_READ_INTERVAL_MICROSECONDS);
    }

    /**
     * The details as one flat JSON object: only scalar entries under string keys (a float rounded), `{}` for none or
     * for no JSON object.
     */
    public static function keepFlatDetailsJson(?string $detailsJson): string
    {
        $details = json_decode((string)($detailsJson ?? '{}'), true);
        $flatDetails = [];
        foreach (is_array($details) ? $details : [] as $detailName => $detailValue) {
            if (is_string($detailName) && (is_scalar($detailValue) || $detailValue === null)) {
                $flatDetails[$detailName] = is_float($detailValue) ? (int)round($detailValue) : $detailValue;
            }
        }
        return (string)json_encode((object)$flatDetails, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function buildRequestKey(int $requestId): string
    {
        return static::REQUEST_KEY_PREFIX . $requestId;
    }

    protected function buildAnswerKey(int $requestId): string
    {
        return static::ANSWER_KEY_PREFIX . $requestId;
    }

    protected function getSharedCache(): ?Cache
    {
        return $this->getAgentRealtimeChannelService()->getSharedCache();
    }

    protected function getAgentRealtimeChannelService(): AgentRealtimeChannelService
    {
        /** @var AgentRealtimeChannelService $agentRealtimeChannelService */
        $agentRealtimeChannelService = DDDService::instance()->getService(AgentRealtimeChannelService::class);
        return $agentRealtimeChannelService;
    }

    protected function getAgentClientPresenceService(): AgentClientPresenceService
    {
        /** @var AgentClientPresenceService $agentClientPresenceService */
        $agentClientPresenceService = DDDService::instance()->getService(AgentClientPresenceService::class);
        return $agentClientPresenceService;
    }

    protected function getAgentScriptedUIClientService(): AgentScriptedUIClientService
    {
        /** @var AgentScriptedUIClientService $agentScriptedUIClientService */
        $agentScriptedUIClientService = DDDService::instance()->getService(AgentScriptedUIClientService::class);
        return $agentScriptedUIClientService;
    }
}
