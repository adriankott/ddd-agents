<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Subjects;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\NotFoundException;

/**
 * The record a purposed conversation works on, e.g. one support ticket: the entity class (the `objectType` a client
 * reads on the record) and its id. A purposed start with a subject resumes the open conversation of the same space,
 * purpose AND subject, so every record gets its own conversation; a start without one keeps the singleton per space
 * and purpose.
 *
 * Both fields come from the client, so the server checks them before anything is stored
 * ({@see findCapViolation()}, {@see findReadableSubjectEntity()}): the class must be a stored entity class of this
 * application and the caller must be able to read the record under its read rights. A record the caller cannot read
 * and an unknown one are not told apart (HTTP 404).
 */
class AIConversationSubject extends ValueObject
{
    /** @var int The characters of an entity class name */
    public const int MAX_ENTITY_CLASS_CHARS = 255;

    /** @var string A fully qualified PHP class name, with or without the leading backslash */
    public const string ENTITY_CLASS_PATTERN = '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    /** @var string|null The fully qualified class of the record, e.g. `App\Domain\Support\Entities\Tickets\SupportTicket` */
    public ?string $subjectEntityClass = null;

    /** @var int|null The id of the record */
    public ?int $subjectEntityId = null;

    /**
     * A subject of one record.
     *
     * @param string $subjectEntityClass
     * @param int $subjectEntityId
     * @return static
     */
    public static function of(string $subjectEntityClass, int $subjectEntityId): static
    {
        $aIConversationSubject = new static();
        $aIConversationSubject->subjectEntityClass = ltrim(trim($subjectEntityClass), '\\');
        $aIConversationSubject->subjectEntityId = $subjectEntityId;
        return $aIConversationSubject;
    }

    /** The class without a leading backslash, as it is stored and compared. */
    public function getNormalizedEntityClass(): string
    {
        return ltrim(trim((string)$this->subjectEntityClass), '\\');
    }

    /**
     * Why the fields cannot name a record, or null when they can: a missing or malformed class, a class that is no
     * entity, an id below 1.
     *
     * Examples: `subjectEntityClass is no entity class: App\Services\Mailer` · `subjectEntityId must be 1 or more`
     */
    public function findCapViolation(): ?string
    {
        $entityClass = $this->getNormalizedEntityClass();
        if ($entityClass === '' || mb_strlen($entityClass) > static::MAX_ENTITY_CLASS_CHARS
            || preg_match(static::ENTITY_CLASS_PATTERN, $entityClass) !== 1) {
            return 'subjectEntityClass must be a fully qualified class name of at most ' . static::MAX_ENTITY_CLASS_CHARS . ' characters';
        }
        // class_exists autoloads only by the validated name; a class that is no entity is refused before its service is asked
        if (!class_exists($entityClass) || !is_subclass_of($entityClass, Entity::class)) {
            return 'subjectEntityClass is no entity class: ' . $entityClass;
        }
        if (($this->subjectEntityId ?? 0) < 1) {
            return 'subjectEntityId must be 1 or more';
        }
        return null;
    }

    /**
     * The record under the caller's read rights.
     *
     * @return Entity
     * @throws BadRequestException when the fields cannot name a record ({@see findCapViolation()})
     * @throws NotFoundException when the record does not exist or the caller cannot read it (one answer for both)
     */
    public function findReadableSubjectEntity(): Entity
    {
        $capViolation = $this->findCapViolation();
        if ($capViolation !== null) {
            throw new BadRequestException($capViolation);
        }
        /** @var Entity $entityClass */
        $entityClass = $this->getNormalizedEntityClass();
        $entitiesService = $entityClass::getService();
        if (!$entitiesService instanceof EntitiesService) {
            throw new BadRequestException('subjectEntityClass has no service to read it: ' . $entityClass);
        }
        $subjectEntity = $entitiesService->find($this->subjectEntityId);
        if (!$subjectEntity instanceof Entity) {
            throw new NotFoundException('The subject of this purpose was not found.');
        }
        return $subjectEntity;
    }

    /** A short label for logs and lock names, e.g. `App\Domain\Support\Entities\Tickets\SupportTicket#13082`. */
    public function describe(): string
    {
        return $this->getNormalizedEntityClass() . '#' . (int)$this->subjectEntityId;
    }
}
