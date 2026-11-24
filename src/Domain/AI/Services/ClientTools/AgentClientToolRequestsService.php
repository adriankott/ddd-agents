<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\ClientTools;

use DDD\Domain\AI\Entities\ClientTools\AgentClientAttachment;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolAnswer;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequestDelivery;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequests;
use DDD\Domain\AI\Repo\DB\ClientTools\DBAgentClientAttachmentModel;
use DDD\Domain\AI\Repo\DB\ClientTools\DBAgentClientToolRequest;
use DDD\Domain\AI\Repo\DB\ClientTools\DBAgentClientToolRequestModel;
use DDD\Domain\AI\Repo\DB\ClientTools\DBAgentClientToolRequests;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolsPurgeResult;
use DDD\Domain\AI\Services\Conversations\AIConversationRealtimeSignalService;
use DDD\Domain\AI\Services\Eval\AgentScriptedUIClientService;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\DDDService;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * The channel between a tool on a worker and the user's client (`Agent.UI`):
 *
 * 1. a tool asks {@see requestAndWait()}: without a fresh client it answers at once; else a request row is stored for
 *    one client and the tool waits, reading the row's status, touching the run's heartbeat once a second;
 * 2. the client's run-state read receives it ({@see findDeliveryForClient()});
 * 3. the client posts its answer ({@see answerRequest()});
 * 4. the tool reads the answer and blanks the stored text ({@see blankAnswerText()}): screen content never stays in
 *    the row, also not on the refused and the expired paths.
 *
 * Status writes that can race (the tool expiring a request, the client answering it) are conditional updates on the
 * status, so exactly one of them wins. The rows are read and created with the user's rights; the conditional updates
 * name the row by id after a read under those rights. A conversation of an evaluation account with a scripted screen
 * is answered by {@see AgentScriptedUIClientService} instead of a client.
 *
 * @method AgentClientToolRequest find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentClientToolRequests findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBAgentClientToolRequest getEntityRepoClassInstance()
 * @method DBAgentClientToolRequests getEntitySetRepoClassInstance()
 */
class AgentClientToolRequestsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentClientToolRequest::class;

    /** @var int How often the waiting tool reads the row, in microseconds */
    public const int WAIT_READ_INTERVAL_MICROSECONDS = 250000;

    /** @var int How often the waiting tool touches the run's heartbeat, in seconds */
    public const int HEARTBEAT_INTERVAL_SECONDS = 1;

    /** @var int The longest wait a tool may ask for (below the UI package's execution ceiling of 25 s) */
    public const int MAX_WAIT_SECONDS = 20;

    /** @var int How long attachments are kept */
    public const int ATTACHMENTS_KEPT_FOR_HOURS = 24;

    /**
     * Stores a request for the user's client and waits for its answer.
     *
     * @param int $conversationId the owner's conversation (the root of a delegation tree)
     * @param int $accountId the owner of the conversation (the Account the tool runs for)
     * @param string|null $toolCallId
     * @param string $requestType one of AgentClientToolRequest::TYPE_*
     * @param array<string, mixed> $arguments flat: strings, ints, bools and lists of strings; encoded at once, at most
     *                                        AgentClientToolRequest::MAX_ARGUMENTS_JSON_CHARS as JSON
     * @param int $waitSeconds at most {@see self::MAX_WAIT_SECONDS}
     * @return AgentClientToolAnswer
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
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
        if (!in_array($requestType, AgentClientToolRequest::TYPES, true) || mb_strlen($argumentsJson) > AgentClientToolRequest::MAX_ARGUMENTS_JSON_CHARS) {
            throw new BadRequestException('A client tool request needs a known type and at most ' . AgentClientToolRequest::MAX_ARGUMENTS_JSON_CHARS . ' characters of arguments.');
        }
        $waitSeconds = max(1, min(static::MAX_WAIT_SECONDS, $waitSeconds));
        $now = new DateTime();
        $agentScriptedUIClientService = $this->getAgentScriptedUIClientService();
        $isScripted = $agentScriptedUIClientService->hasScriptedScreen($conversationId);
        $targetClientId = AgentScriptedUIClientService::SCRIPTED_CLIENT_ID;
        if (!$isScripted) {
            $targetAttachment = $this->getAgentClientAttachmentsService()->findTargetAttachment($conversationId, $now);
            if ($targetAttachment === null) {
                return AgentClientToolAnswer::ofKind(AgentClientToolAnswer::KIND_NO_CLIENT);
            }
            if (($targetAttachment->visible ?? null) === false) {
                // a hidden client does not read (the package's chat stops its reads while hidden): waiting is useless
                return AgentClientToolAnswer::ofKind(AgentClientToolAnswer::KIND_BACKGROUND);
            }
            $targetClientId = (string)$targetAttachment->clientId;
        }

        $request = AgentClientToolRequest::newInstance();
        $request->conversationId = $conversationId;
        $request->accountId = $accountId;
        $request->toolCallId = $toolCallId !== null ? mb_substr($toolCallId, 0, 64) : null;
        $request->requestType = $requestType;
        $request->argumentsJson = $argumentsJson;
        $request->targetClientId = $targetClientId;
        $request->status = AgentClientToolRequest::STATUS_OPEN;
        $request->expiresAt = (clone $now)->modify('+' . $waitSeconds . ' seconds');
        /** @var AgentClientToolRequest $request */
        $request = $this->update($request);
        $requestId = (int)$request->id;

        if ($isScripted) {
            $agentScriptedUIClientService->answerRequest($request);
        } else {
            // a client listening on the realtime signal reads its run state at once and so receives the request
            $this->getRealtimeSignalService()->publishClientToolRequestSignal($conversationId);
        }
        $answer = $this->waitForAnswer($requestId, $conversationId, $waitSeconds, $now);
        $this->blankAnswerText($requestId);
        return $answer;
    }

    /**
     * The request the reading client is to execute now, marked as delivered: the oldest one addressed to the client that is
     * open, or delivered more than AgentClientToolRequest::REDELIVER_AFTER_SECONDS ago without an answer, and not
     * expired.
     *
     * @param int $conversationId
     * @param string $clientId
     * @return AgentClientToolRequestDelivery|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findDeliveryForClient(int $conversationId, string $clientId): ?AgentClientToolRequestDelivery
    {
        if (!AgentClientAttachment::isValidClientId($clientId)) {
            return null;
        }
        $now = new DateTime();
        $redeliverBefore = (clone $now)->modify('-' . AgentClientToolRequest::REDELIVER_AFTER_SECONDS . ' seconds');
        $repoClass = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder = $repoClass::createQueryBuilder();
        $queryBuilder->andWhere("$baseModelAlias.conversationId = :deliveryConversationId")
            ->andWhere("$baseModelAlias.targetClientId = :deliveryClientId")
            ->andWhere("$baseModelAlias.expiresAt > :deliveryNow")
            ->andWhere("($baseModelAlias.status = :deliveryOpen or ($baseModelAlias.status = :deliveryDelivered and $baseModelAlias.deliveredAt < :deliveryRedeliverBefore))")
            ->setParameter('deliveryConversationId', $conversationId)
            ->setParameter('deliveryClientId', $clientId)
            ->setParameter('deliveryNow', $now)
            ->setParameter('deliveryOpen', AgentClientToolRequest::STATUS_OPEN)
            ->setParameter('deliveryDelivered', AgentClientToolRequest::STATUS_DELIVERED)
            ->setParameter('deliveryRedeliverBefore', $redeliverBefore)
            ->addOrderBy("$baseModelAlias.id", 'ASC')
            ->setMaxResults(1);
        $request = $repoClass->find($queryBuilder, false)->first();
        if (!$request instanceof AgentClientToolRequest) {
            return null;
        }
        $marked = $this->updateStatusIfAwaiting((int)$request->id, AgentClientToolRequest::STATUS_DELIVERED, ['deliveredAt' => $now]);
        return $marked ? $request->toDelivery($now) : null;
    }

    /**
     * Stores the answer of the client a request was addressed to.
     *
     * @param int $conversationId
     * @param int $requestId
     * @param string $clientId the answering client
     * @param string $outcome `OK` for a request carried out, else the client's refusal code
     * @param string|null $text at most AgentClientToolRequest::MAX_ANSWER_TEXT_CHARS
     * @param string|null $detailsJson one flat JSON object (strings, numbers, booleans); other entries are dropped, at
     *                                 most AgentClientToolRequest::MAX_ANSWER_DETAILS_JSON_CHARS
     * @return void
     * @throws BadRequestException a value out of its bounds
     * @throws ConflictException the request is no longer waiting for an answer (answered, expired)
     * @throws NotFoundException no such request of this conversation for the caller, or not for this client
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function answerRequest(int $conversationId, int $requestId, string $clientId, string $outcome, ?string $text, ?string $detailsJson): void
    {
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
        $throwErrorsBefore = $this->disableThrowErrors();
        try {
            $request = $this->find($requestId, false);
        } finally {
            $this->restoreThrowErrors($throwErrorsBefore);
        }
        if (!$request instanceof AgentClientToolRequest || ($request->conversationId ?? null) !== $conversationId || ($request->targetClientId ?? null) !== $clientId) {
            throw new NotFoundException('No client tool request ' . $requestId . ' for this client.');
        }
        $status = $outcome === AgentClientToolRequest::OUTCOME_OK ? AgentClientToolRequest::STATUS_ANSWERED : AgentClientToolRequest::STATUS_REFUSED;
        $answered = $this->updateStatusIfAwaiting($requestId, $status, [
            'answeredAt' => new DateTime(),
            'outcome' => $outcome,
            'answerText' => $text,
            'answerChars' => $text !== null ? mb_strlen($text) : 0,
            'answerDetailsJson' => $detailsJson,
        ], true);
        if (!$answered) {
            throw new ConflictException('Client tool request ' . $requestId . ' no longer waits for an answer.');
        }
    }

    /**
     * Removes the answer text of a request: what a screen showed never stays in the row once the tool read it. Called
     * on every path of {@see requestAndWait()} that stored a request (answered, refused, expired).
     *
     * @param int $requestId
     * @return void
     */
    public function blankAnswerText(int $requestId): void
    {
        EntityManagerFactory::getInstance()->createQueryBuilder()
            ->update(DBAgentClientToolRequestModel::class, 'blankedRequest')
            ->set('blankedRequest.answerText', ':blankedText')
            ->where('blankedRequest.id = :blankedRequestId')
            ->setParameter('blankedText', null)
            ->setParameter('blankedRequestId', $requestId)
            ->getQuery()
            ->execute();
    }

    /**
     * Housekeeping (the purge command): requests whose tool stopped waiting become EXPIRED (a worker that died while
     * waiting), their text is blanked, requests older than AgentClientToolRequest::KEPT_FOR_DAYS and attachments older
     * than {@see self::ATTACHMENTS_KEPT_FOR_HOURS} are deleted. Runs as the system.
     */
    public function purge(): AgentClientToolsPurgeResult
    {
        $now = new DateTime();
        $entityManager = EntityManagerFactory::getInstance();
        $expired = (int)$entityManager->createQueryBuilder()
            ->update(DBAgentClientToolRequestModel::class, 'overdueRequest')
            ->set('overdueRequest.status', ':overdueExpired')
            ->set('overdueRequest.outcome', ':overdueOutcome')
            ->set('overdueRequest.answerText', ':overdueText')
            ->where('overdueRequest.status IN (:overdueAwaiting)')
            ->andWhere('overdueRequest.expiresAt < :overdueNow')
            ->setParameter('overdueExpired', AgentClientToolRequest::STATUS_EXPIRED)
            ->setParameter('overdueOutcome', AgentClientToolRequest::OUTCOME_NO_ANSWER)
            ->setParameter('overdueText', null)
            ->setParameter('overdueAwaiting', [AgentClientToolRequest::STATUS_OPEN, AgentClientToolRequest::STATUS_DELIVERED])
            ->setParameter('overdueNow', $now)
            ->getQuery()
            ->execute();
        $deletedRequests = (int)$entityManager->createQueryBuilder()
            ->delete(DBAgentClientToolRequestModel::class, 'oldRequest')
            ->where('oldRequest.created < :oldRequestBefore')
            ->setParameter('oldRequestBefore', (clone $now)->modify('-' . AgentClientToolRequest::KEPT_FOR_DAYS . ' days'))
            ->getQuery()
            ->execute();
        $deletedAttachments = (int)$entityManager->createQueryBuilder()
            ->delete(DBAgentClientAttachmentModel::class, 'oldAttachment')
            ->where('oldAttachment.lastSeenAt < :oldAttachmentBefore')
            ->setParameter('oldAttachmentBefore', (clone $now)->modify('-' . static::ATTACHMENTS_KEPT_FOR_HOURS . ' hours'))
            ->getQuery()
            ->execute();
        $agentClientToolsPurgeResult = new AgentClientToolsPurgeResult();
        $agentClientToolsPurgeResult->expiredRequestCount = $expired;
        $agentClientToolsPurgeResult->deletedRequestCount = $deletedRequests;
        $agentClientToolsPurgeResult->deletedAttachmentCount = $deletedAttachments;
        return $agentClientToolsPurgeResult;
    }

    /**
     * Waits until the request is answered or refused or the wait is over; on the end of the wait the request becomes
     * EXPIRED, unless the answer won the race.
     *
     * @param int $requestId
     * @param int $conversationId
     * @param int $waitSeconds
     * @param DateTime $startedAt
     * @return AgentClientToolAnswer
     */
    protected function waitForAnswer(int $requestId, int $conversationId, int $waitSeconds, DateTime $startedAt): AgentClientToolAnswer
    {
        $deadline = microtime(true) + $waitSeconds;
        $nextHeartbeatAt = 0.0;
        while (true) {
            $row = $this->readRequestState($requestId);
            if ($row !== null && in_array($row['status'], [AgentClientToolRequest::STATUS_ANSWERED, AgentClientToolRequest::STATUS_REFUSED], true)) {
                return $this->buildAnswerOfRow($requestId, $row, $startedAt);
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            if (microtime(true) >= $nextHeartbeatAt) {
                $this->touchRunHeartbeat($conversationId);
                $nextHeartbeatAt = microtime(true) + static::HEARTBEAT_INTERVAL_SECONDS;
            }
            usleep(static::WAIT_READ_INTERVAL_MICROSECONDS);
        }
        $this->updateStatusIfAwaiting($requestId, AgentClientToolRequest::STATUS_EXPIRED, ['outcome' => AgentClientToolRequest::OUTCOME_NO_ANSWER]);
        $row = $this->readRequestState($requestId);
        if ($row !== null && in_array($row['status'], [AgentClientToolRequest::STATUS_ANSWERED, AgentClientToolRequest::STATUS_REFUSED], true)) {
            return $this->buildAnswerOfRow($requestId, $row, $startedAt);
        }
        $answer = AgentClientToolAnswer::ofKind(AgentClientToolAnswer::KIND_NO_ANSWER);
        $answer->requestId = $requestId;
        $answer->outcome = AgentClientToolRequest::OUTCOME_NO_ANSWER;
        $answer->secondsWaited = (new DateTime())->getTimestamp() - $startedAt->getTimestamp();
        return $answer;
    }

    /**
     * @param int $requestId
     * @param array<string, mixed> $row
     * @param DateTime $startedAt
     * @return AgentClientToolAnswer
     */
    protected function buildAnswerOfRow(int $requestId, array $row, DateTime $startedAt): AgentClientToolAnswer
    {
        $answer = AgentClientToolAnswer::ofKind(
            $row['status'] === AgentClientToolRequest::STATUS_ANSWERED ? AgentClientToolAnswer::KIND_ANSWERED : AgentClientToolAnswer::KIND_REFUSED
        );
        $answer->requestId = $requestId;
        $answer->outcome = $row['outcome'] ?? null;
        $answer->text = $row['answerText'] ?? null;
        $answer->detailsJson = static::keepFlatDetailsJson($row['answerDetailsJson'] ?? null);
        $answer->secondsWaited = (new DateTime())->getTimestamp() - $startedAt->getTimestamp();
        return $answer;
    }

    /**
     * The status and answer of a request as plain values (no entity: the read must see a write of another process,
     * never a cached instance).
     *
     * @param int $requestId
     * @return array<string, mixed>|null
     */
    protected function readRequestState(int $requestId): ?array
    {
        $rows = EntityManagerFactory::getInstance()->createQueryBuilder()
            ->select('polledRequest.status, polledRequest.outcome, polledRequest.answerText, polledRequest.answerDetailsJson')
            ->from(DBAgentClientToolRequestModel::class, 'polledRequest')
            ->where('polledRequest.id = :polledRequestId')
            ->setParameter('polledRequestId', $requestId)
            ->getQuery()
            ->getArrayResult();
        return $rows[0] ?? null;
    }

    /**
     * Sets a status (and further fields) only while the request still waits for an answer and, unless told otherwise,
     * whatever its expiry: exactly one of a racing answer and a racing expiry wins.
     *
     * @param int $requestId
     * @param string $status
     * @param array<string, mixed> $fields further fields to set
     * @param bool $onlyBeforeExpiry whether the request must not be expired yet (an answer)
     * @return bool Whether the row was changed
     */
    protected function updateStatusIfAwaiting(int $requestId, string $status, array $fields = [], bool $onlyBeforeExpiry = false): bool
    {
        $queryBuilder = EntityManagerFactory::getInstance()->createQueryBuilder()
            ->update(DBAgentClientToolRequestModel::class, 'awaitedRequest')
            ->set('awaitedRequest.status', ':awaitedNewStatus')
            ->set('awaitedRequest.updated', ':awaitedNow')
            ->where('awaitedRequest.id = :awaitedRequestId')
            ->andWhere('awaitedRequest.status IN (:awaitedStatuses)')
            ->setParameter('awaitedNewStatus', $status)
            ->setParameter('awaitedNow', new DateTime())
            ->setParameter('awaitedRequestId', $requestId)
            ->setParameter('awaitedStatuses', [AgentClientToolRequest::STATUS_OPEN, AgentClientToolRequest::STATUS_DELIVERED]);
        if ($onlyBeforeExpiry) {
            $queryBuilder->andWhere('awaitedRequest.expiresAt > :awaitedExpiryNow')->setParameter('awaitedExpiryNow', new DateTime());
        }
        foreach ($fields as $fieldName => $value) {
            $queryBuilder->set('awaitedRequest.' . $fieldName, ':awaitedField_' . $fieldName)->setParameter('awaitedField_' . $fieldName, $value);
        }
        return (int)$queryBuilder->getQuery()->execute() === 1;
    }

    /**
     * Keeps the run alive in the reaper's eyes while the tool waits (best effort, as the module documents it).
     *
     * @param int $conversationId
     * @return void
     */
    protected function touchRunHeartbeat(int $conversationId): void
    {
        /** @var AIConversationRunClaimsService $aiConversationRunClaimsService */
        $aiConversationRunClaimsService = DDDService::instance()->getService(AIConversationRunClaimsService::class);
        $aiConversationRunClaimsService->touchRunningHeartbeat($conversationId);
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

    /**
     * @return AgentClientAttachmentsService
     */
    protected function getAgentClientAttachmentsService(): AgentClientAttachmentsService
    {
        /** @var AgentClientAttachmentsService $agentClientAttachmentsService */
        $agentClientAttachmentsService = DDDService::instance()->getService(AgentClientAttachmentsService::class);
        return $agentClientAttachmentsService;
    }

    /**
     * @return AIConversationRealtimeSignalService
     */
    protected function getRealtimeSignalService(): AIConversationRealtimeSignalService
    {
        /** @var AIConversationRealtimeSignalService $aIConversationRealtimeSignalService */
        $aIConversationRealtimeSignalService = DDDService::instance()->getService(AIConversationRealtimeSignalService::class);
        return $aIConversationRealtimeSignalService;
    }

    protected function getAgentScriptedUIClientService(): AgentScriptedUIClientService
    {
        /** @var AgentScriptedUIClientService $agentScriptedUIClientService */
        $agentScriptedUIClientService = DDDService::instance()->getService(AgentScriptedUIClientService::class);
        return $agentScriptedUIClientService;
    }
}
