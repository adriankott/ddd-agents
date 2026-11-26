<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Goals;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaceModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AgentGoals')]
class DBAgentGoalModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentGoal';

	public const string TABLE_NAME = 'AgentGoals';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal';

	public static array $virtualColumns = ['virtualCurrentDedupKey' => [ 'createIndex' => false, 'stored' => true, 'as' => '(CASE WHEN validTo IS NULL AND invalidatedAt IS NULL THEN CONCAT_WS(\'|\', scopeKey, statementHash) ELSE NULL END)', 'referenceColumn' => 'currentDedupKey', 'referenceColumnStored' => false, ]];

	#[ORM\Column(type: 'integer')]
	public int $accountId;

	#[ORM\Column(type: 'integer')]
	public ?int $agentSpaceId;

	#[ORM\Column(type: 'string')]
	public ?string $holder = 'OWNER';

	#[ORM\Column(type: 'string')]
	public ?string $horizonLevel = 'LONG_TERM';

	#[ORM\Column(type: 'string')]
	public ?string $status = 'ACTIVE';

	#[ORM\Column(type: 'string')]
	public ?string $provenance = 'USER_STATED';

	#[ORM\Column(type: 'string')]
	public ?string $statement;

	#[ORM\Column(type: 'string')]
	public ?string $reason;

	#[ORM\Column(type: 'string')]
	public ?string $successSignal;

	#[ORM\Column(type: 'date')]
	public ?DateTime $targetDate;

	#[ORM\Column(type: 'string')]
	public ?string $statusNote;

	#[ORM\Column(type: 'boolean')]
	public ?bool $requiresUserAction = false;

	#[ORM\Column(type: 'boolean')]
	public ?bool $retryable = false;

	#[ORM\Column(type: 'integer')]
	public ?int $priority = 0;

	#[ORM\Column(type: 'integer')]
	public ?int $sourceMessageId;

	#[ORM\Column(type: 'string')]
	public ?string $blueprintKey;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $revisitDueAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $achievedAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $validTo;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $invalidatedAt;

	#[ORM\Column(type: 'integer')]
	public ?int $supersededByGoalId;

	#[ORM\Column(type: 'integer')]
	public ?int $parentGoalId;

	#[ORM\Column(type: 'string')]
	public ?string $scopeKey;

	#[ORM\Column(type: 'string')]
	public ?string $statementHash;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\Column(type: 'string')]
	public string $virtualCurrentDedupKey;

	#[ORM\ManyToOne(targetEntity: DBAccountModel::class)]
	#[ORM\JoinColumn(name: 'accountId', referencedColumnName: 'id')]
	public DBAccountModel $account;

	#[ORM\ManyToOne(targetEntity: DBAgentSpaceModel::class)]
	#[ORM\JoinColumn(name: 'agentSpaceId', referencedColumnName: 'id')]
	public ?DBAgentSpaceModel $agentSpace;

}