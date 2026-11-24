<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\ClientTools;

use DDD\Domain\AI\Entities\ClientTools\AgentClientAttachment;
use DDD\Domain\AI\Entities\ClientTools\AgentClientAttachments;
use DDD\Domain\AI\Entities\ClientTools\AgentUIState;
use DDD\Domain\AI\Repo\DB\ClientTools\DBAgentClientAttachment;
use DDD\Domain\AI\Repo\DB\ClientTools\DBAgentClientAttachments;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * The clients (browser tabs, apps) that show a conversation with the agent (`Agent.UI`). A client's run-state read and
 * its messages stamp its row with the UI state it reported ({@see stampClient()}); a send marks it as the client the
 * user works in; a client tool request goes to the client {@see findTargetAttachment()} picks. All reads and writes run
 * with the user's rights: an Account sees only its own clients.
 *
 * @method AgentClientAttachment find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AgentClientAttachments findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBAgentClientAttachment getEntityRepoClassInstance()
 * @method DBAgentClientAttachments getEntitySetRepoClassInstance()
 */
class AgentClientAttachmentsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AgentClientAttachment::class;

    /** @var int The most clients of one conversation that are considered */
    public const int MAX_ATTACHMENTS_PER_CONVERSATION = 20;

    /**
     * Stamps one read or send of a client: creates its row on the first one, and writes it again only when the
     * throttle passed or visibility, route or UI state changed (a send always writes). A value that is no client id is
     * ignored; the UI state is normalized ({@see AgentUIState::normalize()}), never refused.
     *
     * @param int $conversationId the root conversation the client shows
     * @param int $accountId the Account that read or sent (the owner of the conversation)
     * @param string|null $clientId
     * @param AgentUIState|null $agentUIState what the client reported; null keeps the stored state
     * @param bool $isOwnerSend whether the stamp comes with a send of the user
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function stampClient(int $conversationId, int $accountId, ?string $clientId, ?AgentUIState $agentUIState, bool $isOwnerSend = false): void
    {
        if (!AgentClientAttachment::isValidClientId($clientId)) {
            return;
        }
        $normalizedUIState = $agentUIState?->normalize();
        $uiStateJson = $normalizedUIState !== null ? static::encodeUIState($normalizedUIState) : null;
        $now = new DateTime();
        $attachment = $this->findAttachmentOfClient($conversationId, (string)$clientId);
        $visible = $normalizedUIState !== null ? $normalizedUIState->visible : ($attachment->visible ?? null);
        $route = $normalizedUIState !== null ? $normalizedUIState->routePath : ($attachment->route ?? null);
        if ($attachment !== null && !$isOwnerSend && !$attachment->needsStamp($now, $visible, $route, $uiStateJson)) {
            return;
        }
        if ($attachment === null) {
            $attachment = AgentClientAttachment::newInstance();
            $attachment->conversationId = $conversationId;
            $attachment->accountId = $accountId;
            $attachment->clientId = (string)$clientId;
            $attachment->uiStateJson = null;
        }
        $attachment->lastSeenAt = $now;
        $attachment->visible = $visible;
        $attachment->route = $route;
        if ($uiStateJson !== null) {
            $attachment->uiStateJson = $uiStateJson;
        }
        if ($isOwnerSend) {
            $attachment->lastOwnerSendAt = $now;
        }
        $this->update($attachment);
    }

    /** The stored form of a normalized UI state: one JSON object, the same for the same state */
    public static function encodeUIState(AgentUIState $agentUIState): string
    {
        return (string)json_encode($agentUIState->toObject(false), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The client a client tool request goes to: among the clients seen within AgentClientAttachment::FRESH_FOR_SECONDS,
     * the one the user sent from last, else the newest visible one, else the newest one.
     *
     * @return AgentClientAttachment|null null when no client of the conversation is fresh
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findTargetAttachment(int $conversationId, \DateTimeInterface $now): ?AgentClientAttachment
    {
        $freshAttachments = AgentClientAttachments::newInstance();
        foreach ($this->findAttachmentsOfConversation($conversationId)->getElements() as $attachment) {
            if ($attachment->isFresh($now)) {
                $freshAttachments->add($attachment);
            }
        }
        return static::pickTargetAttachment($freshAttachments);
    }

    /** The choice of {@see findTargetAttachment()} among fresh clients, without reading anything */
    public static function pickTargetAttachment(AgentClientAttachments $freshAttachments): ?AgentClientAttachment
    {
        $lastSentFrom = null;
        foreach ($freshAttachments->getElements() as $attachment) {
            $lastOwnerSendAt = $attachment->lastOwnerSendAt ?? null;
            if ($lastOwnerSendAt !== null
                && ($lastSentFrom === null || $lastOwnerSendAt->getTimestamp() > $lastSentFrom->lastOwnerSendAt->getTimestamp())) {
                $lastSentFrom = $attachment;
            }
        }
        if ($lastSentFrom !== null) {
            return $lastSentFrom;
        }
        $picked = null;
        foreach ($freshAttachments->getElements() as $attachment) {
            if ($picked === null || static::isPreferredOver($attachment, $picked)) {
                $picked = $attachment;
            }
        }
        return $picked;
    }

    /** Whether the candidate wins: a visible client before a hidden one, then the newer read */
    protected static function isPreferredOver(AgentClientAttachment $candidate, AgentClientAttachment $current): bool
    {
        $candidateVisible = ($candidate->visible ?? false) === true;
        $currentVisible = ($current->visible ?? false) === true;
        if ($candidateVisible !== $currentVisible) {
            return $candidateVisible;
        }
        return ($candidate->lastSeenAt ?? null)?->getTimestamp() > ($current->lastSeenAt ?? null)?->getTimestamp();
    }

    /**
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findAttachmentOfClient(int $conversationId, string $clientId): ?AgentClientAttachment
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder = $repoClass::createQueryBuilder();
        $queryBuilder->andWhere("$baseModelAlias.conversationId = :attachmentConversationId")
            ->andWhere("$baseModelAlias.clientId = :attachmentClientId")
            ->setParameter('attachmentConversationId', $conversationId)
            ->setParameter('attachmentClientId', $clientId)
            ->setMaxResults(1);
        return $repoClass->find($queryBuilder, false)->first();
    }

    /**
     * The clients of the conversation the user may read, newest first.
     *
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findAttachmentsOfConversation(int $conversationId): AgentClientAttachments
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder = $repoClass::createQueryBuilder();
        $queryBuilder->andWhere("$baseModelAlias.conversationId = :attachmentsConversationId")
            ->setParameter('attachmentsConversationId', $conversationId)
            ->addOrderBy("$baseModelAlias.lastSeenAt", 'DESC')
            ->setMaxResults(static::MAX_ATTACHMENTS_PER_CONVERSATION);
        return $repoClass->find($queryBuilder, false);
    }
}
