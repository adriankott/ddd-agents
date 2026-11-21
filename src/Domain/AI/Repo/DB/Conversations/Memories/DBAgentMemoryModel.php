<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Memories;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Domain\AI\Repo\DB\AgentSpaces\DBAgentSpaceModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AgentMemories')]
class DBAgentMemoryModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentMemory';

	public const string TABLE_NAME = 'AgentMemories';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory';

	public static array $virtualColumns = ['virtualCurrentDedupKey' => [ 'createIndex' => false, 'stored' => true, 'as' => '(CASE WHEN validTo IS NULL AND invalidatedAt IS NULL THEN CONCAT_WS(\'|\', scopeKey, contentHash) ELSE NULL END)', 'referenceColumn' => 'currentDedupKey', 'referenceColumnStored' => false, ]];

	#[ORM\Column(type: 'integer')]
	public int $accountId;

	#[ORM\Column(type: 'integer')]
	public ?int $agentSpaceId;

	#[ORM\Column(type: 'string')]
	public ?string $memoryType = 'FACT';

	#[ORM\Column(type: 'string')]
	public ?string $subject = 'BUSINESS';

	#[ORM\Column(type: 'string')]
	public ?string $content;

	#[ORM\Column(type: 'vector', length: 1536)]
	public mixed $embedding;

	#[ORM\Column(type: 'string')]
	public ?string $source = 'USER_STATED';

	#[ORM\Column(type: 'string')]
	public ?string $contentHash;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $validFrom;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $validTo;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $invalidatedAt;

	#[ORM\Column(type: 'integer')]
	public ?int $supersededByMemoryId;

	#[ORM\Column(type: 'string')]
	public ?string $scopeKey;

	#[ORM\Column(type: 'integer')]
	public ?int $sourceMessageId;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $sourceInstant;

	#[ORM\Column(type: 'string')]
	public ?string $sourceTimezone;

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