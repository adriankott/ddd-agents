<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\AIConversations;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationBoundSubject;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationBoundSubjects;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubject;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubjectBinding;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubjectDescription;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Subjects\DBAIConversationSubjectBindingModel;
use DDD\Domain\AI\Services\Conversations\Subjects\AIConversationSubjectDescriber;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use Doctrine\DBAL\ArrayParameterType;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/**
 * The subjects of conversations ({@see AIConversationSubject}): which conversation is about which record.
 *
 * - A purposed start with a subject resumes the open conversation of the same space, purpose and subject
 *   ({@see findNewestConversationIdOfSubject()}).
 * - A free conversation started about a record is bound to it; the record's conversations are listed newest first
 *   ({@see restrictConversationQueryToSubject()}, {@see AIConversationsService::findAllVisibleToCustomerOfSubject()}).
 * - What the record is comes from the application ({@see AIConversationSubjectDescriber}), is stored once on the
 *   conversation's working state when it is bound ({@see AIConversationsService::persistSubjectDescriptionSlice()})
 *   and reaches the model in every call of the conversation (the system segment `CONVERSATION_SUBJECT`).
 *
 * The rows live in their own table ({@see AIConversationSubjectBinding}). A host that has not created it yet keeps
 * working without subjects: {@see isBindingTableAvailable()} is false, a start with a subject then behaves like a start
 * without one, and nothing is stored or read here.
 */
class AIConversationSubjectBindingsService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AIConversationSubjectBinding::class;

    /** @var int How long a "table missing" answer is kept in a worker before the catalog is asked again */
    public const int MISSING_TABLE_RECHECK_SECONDS = 60;

    /** @var bool|null Whether the table exists, per process; null = not asked yet */
    protected static ?bool $bindingTableAvailable = null;

    /** @var int When {@see $bindingTableAvailable} was last asked (unix seconds) */
    protected static int $bindingTableCheckedAt = 0;

    /** @var iterable<AIConversationSubjectDescriber> */
    protected iterable $aIConversationSubjectDescribers = [];

    /** @param iterable<AIConversationSubjectDescriber> $aIConversationSubjectDescribers */
    #[Required]
    public function setAIConversationSubjectDescribers(
        #[AutowireIterator(AIConversationSubjectDescriber::TAG)]
        iterable $aIConversationSubjectDescribers
    ): void {
        $this->aIConversationSubjectDescribers = $aIConversationSubjectDescribers;
    }

    /**
     * Whether `AIConversationSubjectBindings` exists in the connected database (MariaDB or PostgreSQL). A yes is kept
     * for the life of the process; a no is asked again after {@see MISSING_TABLE_RECHECK_SECONDS}, so a worker picks
     * the table up once the DDL ran.
     */
    public function isBindingTableAvailable(): bool
    {
        if (static::$bindingTableAvailable === true
            || (static::$bindingTableAvailable === false && time() - static::$bindingTableCheckedAt < static::MISSING_TABLE_RECHECK_SECONDS)) {
            return static::$bindingTableAvailable;
        }
        $connection = EntityManagerFactory::getInstance()->getConnection();
        $schemaFunction = EntityManagerFactory::getDialect()->name() === SqlDialect::POSTGRES ? 'current_schema()' : 'DATABASE()';
        $matchingTableCount = (int)$connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = {$schemaFunction} AND table_name = :tableName",
            ['tableName' => DBAIConversationSubjectBindingModel::getTableName()]
        );
        static::$bindingTableAvailable = $matchingTableCount > 0;
        static::$bindingTableCheckedAt = time();
        return static::$bindingTableAvailable;
    }

    /** Forget the cached table check (tests, and a host that runs the DDL inside a long process). */
    public static function forgetBindingTableCheck(): void
    {
        static::$bindingTableAvailable = null;
        static::$bindingTableCheckedAt = 0;
    }

    /**
     * Store the subject of a freshly started conversation: a purposed one (the row carries its purpose) or a free one
     * (no purpose), in a space or at account level. The application's describer is asked once, here, and its answer
     * is stored on the conversation's working state ({@see describeSubject()},
     * {@see AIConversationsService::persistSubjectDescriptionSlice()}): called before the first turn is dispatched, the
     * first model call already reads what the record is. Nothing when the table is missing.
     *
     * @param AIConversation $conversation a stored conversation
     * @param AIConversationSubject $aIConversationSubject
     * @param Entity|null $subjectEntity the record when the caller has read it already; else it is read under the caller's rights for the describer
     * @return AIConversationSubjectBinding|null null when the table is missing
     * @throws InternalErrorException when the conversation is not stored
     */
    public function bindSubjectToConversation(AIConversation $conversation, AIConversationSubject $aIConversationSubject, ?Entity $subjectEntity = null): ?AIConversationSubjectBinding
    {
        if (!isset($conversation->id)) {
            throw new InternalErrorException('A subject is bound only to a stored conversation.');
        }
        if (!$this->isBindingTableAvailable()) {
            return null;
        }
        $aIConversationSubjectBinding = AIConversationSubjectBinding::newInstance();
        $aIConversationSubjectBinding->aiConversationId = (int)$conversation->id;
        $aIConversationSubjectBinding->agentSpaceId = ($conversation->agentSpaceId ?? null) !== null ? (int)$conversation->agentSpaceId : null;
        $purposeName = trim((string)($conversation->purposeName ?? ''));
        $aIConversationSubjectBinding->purposeName = $purposeName !== '' ? $purposeName : null;
        $aIConversationSubjectBinding->subjectEntityClass = $aIConversationSubject->getNormalizedEntityClass();
        $aIConversationSubjectBinding->subjectEntityId = (int)$aIConversationSubject->subjectEntityId;
        /** @var AIConversationSubjectBinding $storedBinding */
        $storedBinding = $aIConversationSubjectBinding->insert();
        $aIConversationSubjectDescription = $this->describeSubject($aIConversationSubject, $subjectEntity);
        if ($aIConversationSubjectDescription !== null) {
            /** @var AIConversationsService $aIConversationsService */
            $aIConversationsService = DDDService::instance()->getService(AIConversationsService::class);
            $aIConversationsService->persistSubjectDescriptionSlice($conversation, $aIConversationSubjectDescription);
        }
        return $storedBinding;
    }

    /**
     * What the application says about the record: the answer of the first describer that names the record's class
     * (or a parent class of it) and returns one. Null when no describer names the class, none has an answer, the
     * record cannot be read under the caller's rights, or the describer failed (logged as a warning; the conversation
     * is then bound without a description and its model reads no record segment).
     *
     * @param AIConversationSubject $aIConversationSubject
     * @param Entity|null $subjectEntity the record when the caller has read it already
     * @return AIConversationSubjectDescription|null
     */
    public function describeSubject(AIConversationSubject $aIConversationSubject, ?Entity $subjectEntity = null): ?AIConversationSubjectDescription
    {
        $subjectEntityClass = $aIConversationSubject->getNormalizedEntityClass();
        foreach ($this->aIConversationSubjectDescribers as $aIConversationSubjectDescriber) {
            if (!$this->isDescriberOfEntityClass($aIConversationSubjectDescriber, $subjectEntityClass)) {
                continue;
            }
            try {
                $subjectEntity ??= $aIConversationSubject->findReadableSubjectEntity();
                $aIConversationSubjectDescription = $aIConversationSubjectDescriber->describeSubject($aIConversationSubject, $subjectEntity);
            } catch (Throwable $throwable) {
                DDDService::instance()->getLogger()->warning(
                    'AIConversationSubjectBindingsService: the subject ' . $aIConversationSubject->describe() . ' could not be described',
                    ['describer' => $aIConversationSubjectDescriber::class, 'error' => mb_substr($throwable->getMessage(), 0, 500)]
                );
                return null;
            }
            if ($aIConversationSubjectDescription instanceof AIConversationSubjectDescription && $aIConversationSubjectDescription->hasDescription()) {
                return $aIConversationSubjectDescription;
            }
        }
        return null;
    }

    /** Whether `$aIConversationSubjectDescriber` names `$subjectEntityClass` or a parent class of it. */
    protected function isDescriberOfEntityClass(AIConversationSubjectDescriber $aIConversationSubjectDescriber, string $subjectEntityClass): bool
    {
        foreach ($aIConversationSubjectDescriber->getSubjectEntityClasses() as $describedEntityClass) {
            if (is_a($subjectEntityClass, ltrim($describedEntityClass, '\\'), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The binding of a conversation, or null: none, or the table is missing. Read without the rights filter: the
     * caller holds the conversation, which it read under its rights (or builds its prompt on a worker).
     *
     * @param int $aiConversationId
     * @return AIConversationSubjectBinding|null
     */
    public function findBindingOfConversation(int $aiConversationId): ?AIConversationSubjectBinding
    {
        if ($aiConversationId <= 0 || !$this->isBindingTableAvailable()) {
            return null;
        }
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$baseModelAlias}.aiConversationId = :aiConversationId")
            ->setParameter('aiConversationId', $aiConversationId)
            ->setMaxResults(1);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $aIConversationSubjectBinding = $repoClass->find($queryBuilder, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        return $aIConversationSubjectBinding instanceof AIConversationSubjectBinding ? $aIConversationSubjectBinding : null;
    }

    /**
     * The records a set of conversations is bound to, one entry per bound conversation (a conversation without a
     * record has none): the binding and what the conversation's working state stores about the record. One read of
     * the binding rows, without the rights filter: the caller holds the conversations, which it read under its
     * rights. Empty where the table is missing.
     *
     * @param AIConversations $aIConversations conversations the caller read under its rights
     * @return AIConversationBoundSubjects
     */
    public function findBoundSubjectsOfConversations(AIConversations $aIConversations): AIConversationBoundSubjects
    {
        $aIConversationBoundSubjects = new AIConversationBoundSubjects();
        $conversationsById = [];
        foreach ($aIConversations->getElements() as $aIConversation) {
            if (isset($aIConversation->id)) {
                $conversationsById[(int)$aIConversation->id] = $aIConversation;
            }
        }
        if ($conversationsById === [] || !$this->isBindingTableAvailable()) {
            return $aIConversationBoundSubjects;
        }
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$baseModelAlias}.aiConversationId IN (:aiConversationIds)")
            ->setParameter('aiConversationIds', array_keys($conversationsById), ArrayParameterType::INTEGER);
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $aIConversationSubjectBindings = $repoClass->find($queryBuilder, false);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        foreach ($aIConversationSubjectBindings?->getElements() ?? [] as $aIConversationSubjectBinding) {
            $aIConversation = $conversationsById[(int)($aIConversationSubjectBinding->aiConversationId ?? 0)] ?? null;
            if ($aIConversation === null) {
                continue;
            }
            $aIConversationBoundSubject = AIConversationBoundSubject::of($aIConversationSubjectBinding, $aIConversation->getAgentWorkingState()?->subjectDescription);
            $aIConversationBoundSubjects->add($aIConversationBoundSubject);
        }
        return $aIConversationBoundSubjects;
    }

    /**
     * Restrict a conversation query to the conversations bound to `$aIConversationSubject`, of every space and
     * purpose. The caller's own conditions (read rights, visibility) stay as they are.
     *
     * @param DoctrineQueryBuilder $queryBuilder a builder on the conversation model
     * @param string $conversationAlias the alias of the conversation model in the query
     * @param AIConversationSubject $aIConversationSubject
     * @return void
     */
    public static function restrictConversationQueryToSubject(DoctrineQueryBuilder $queryBuilder, string $conversationAlias, AIConversationSubject $aIConversationSubject): void
    {
        $bindingModelClass = DBAIConversationSubjectBindingModel::class;
        $queryBuilder->andWhere(
            "EXISTS (SELECT subjectBindingOfConversation.id FROM {$bindingModelClass} subjectBindingOfConversation"
            . " WHERE subjectBindingOfConversation.aiConversationId = {$conversationAlias}.id"
            . ' AND subjectBindingOfConversation.subjectEntityClass = :subjectEntityClassOfBinding'
            . ' AND subjectBindingOfConversation.subjectEntityId = :subjectEntityIdOfBinding)'
        )
            ->setParameter('subjectEntityClassOfBinding', $aIConversationSubject->getNormalizedEntityClass())
            ->setParameter('subjectEntityIdOfBinding', (int)$aIConversationSubject->subjectEntityId);
    }

    /**
     * The id of the newest conversation of `$agentSpace` and `$purposeName` that works on `$aIConversationSubject`,
     * or null: none, or the table is missing. With `$includeCompleted` false a COMPLETED or CANCELLED conversation does
     * not count (the purpose's {@see \DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose::$completedConversationStaysSingleton}).
     * One read on the composite index of the binding table, joined to the conversation for its run state; the
     * caller reads the conversation itself under its rights.
     *
     * @param AgentSpace $agentSpace a stored space
     * @param string $purposeName
     * @param AIConversationSubject $aIConversationSubject
     * @param bool $includeCompleted
     * @return int|null
     * @throws InternalErrorException when the space is not stored
     */
    public function findNewestConversationIdOfSubject(
        AgentSpace $agentSpace,
        string $purposeName,
        AIConversationSubject $aIConversationSubject,
        bool $includeCompleted = false
    ): ?int {
        if (!$this->isBindingTableAvailable()) {
            return null;
        }
        $agentSpaceId = AgentSpace::getIdOfStoredAgentSpace($agentSpace);
        $sqlDialect = EntityManagerFactory::getDialect();
        $bindingTable = $sqlDialect->quote(DBAIConversationSubjectBindingModel::getTableName());
        $conversationTable = $sqlDialect->quote(DBAIConversationModel::getTableName());
        $sql = "SELECT b.{$sqlDialect->quote('aiConversationId')} FROM {$bindingTable} b"
            . " INNER JOIN {$conversationTable} c ON c.{$sqlDialect->quote('id')} = b.{$sqlDialect->quote('aiConversationId')}"
            . " WHERE b.{$sqlDialect->quote('agentSpaceId')} = :agentSpaceId"
            . " AND b.{$sqlDialect->quote('purposeName')} = :purposeName"
            . " AND b.{$sqlDialect->quote('subjectEntityClass')} = :subjectEntityClass"
            . " AND b.{$sqlDialect->quote('subjectEntityId')} = :subjectEntityId";
        $parameters = [
            'agentSpaceId' => $agentSpaceId,
            'purposeName' => $purposeName,
            'subjectEntityClass' => $aIConversationSubject->getNormalizedEntityClass(),
            'subjectEntityId' => (int)$aIConversationSubject->subjectEntityId,
        ];
        $types = [];
        if (!$includeCompleted) {
            $sql .= " AND c.{$sqlDialect->quote('runState')} NOT IN (:closedRunStates)";
            $parameters['closedRunStates'] = AIConversationRunState::CLOSED;
            $types['closedRunStates'] = ArrayParameterType::STRING;
        }
        $sql .= " ORDER BY b.{$sqlDialect->quote('aiConversationId')} DESC LIMIT 1";
        $conversationId = EntityManagerFactory::getInstance()->getConnection()->fetchOne($sql, $parameters, $types);
        return $conversationId === false || $conversationId === null ? null : (int)$conversationId;
    }
}
