<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubject;
use DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubjectBinding;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Repo\DB\Conversations\Subjects\DBAIConversationSubjectBindingModel;
use DDD\Domain\Base\Repo\DB\Dialect\SqlDialect;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Doctrine\DBAL\ArrayParameterType;

/**
 * The subjects of purposed conversations ({@see AIConversationSubject}): which conversation works on which record, so
 * a purposed start with a subject resumes the open conversation of the same space, purpose and subject.
 *
 * The rows live in their own table ({@see AIConversationSubjectBinding}). A host that has not created it yet keeps
 * working without subjects: {@see isBindingTableAvailable()} is false, a start with a subject then behaves like a start
 * without one (the singleton of the space and purpose), and nothing is stored or read here.
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
     * Store the subject of a freshly started purposed conversation. Nothing when the table is missing.
     *
     * @param AIConversation $conversation a stored purposed conversation of a space
     * @param AIConversationSubject $aIConversationSubject
     * @return AIConversationSubjectBinding|null null when the table is missing
     * @throws InternalErrorException when the conversation is not stored, has no space or no purpose
     */
    public function bindSubjectToConversation(AIConversation $conversation, AIConversationSubject $aIConversationSubject): ?AIConversationSubjectBinding
    {
        if (!isset($conversation->id) || ($conversation->agentSpaceId ?? null) === null || ($conversation->purposeName ?? null) === null) {
            throw new InternalErrorException('A subject is bound only to a stored purposed conversation of a space.');
        }
        if (!$this->isBindingTableAvailable()) {
            return null;
        }
        $aIConversationSubjectBinding = AIConversationSubjectBinding::newInstance();
        $aIConversationSubjectBinding->aiConversationId = (int)$conversation->id;
        $aIConversationSubjectBinding->agentSpaceId = (int)$conversation->agentSpaceId;
        $aIConversationSubjectBinding->purposeName = (string)$conversation->purposeName;
        $aIConversationSubjectBinding->subjectEntityClass = $aIConversationSubject->getNormalizedEntityClass();
        $aIConversationSubjectBinding->subjectEntityId = (int)$aIConversationSubject->subjectEntityId;
        /** @var AIConversationSubjectBinding $storedBinding */
        $storedBinding = $aIConversationSubjectBinding->insert();
        return $storedBinding;
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
