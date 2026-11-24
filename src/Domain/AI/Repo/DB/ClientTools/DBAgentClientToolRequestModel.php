<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\ClientTools;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\Common\Repo\DB\Accounts\DBAccountModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AgentClientToolRequests')]
class DBAgentClientToolRequestModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentClientToolRequest';

	public const string TABLE_NAME = 'AgentClientToolRequests';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest';

	#[ORM\Column(type: 'integer')]
	public int $conversationId;

	#[ORM\Column(type: 'integer')]
	public int $accountId;

	#[ORM\Column(type: 'string')]
	public ?string $toolCallId;

	#[ORM\Column(type: 'string')]
	public string $requestType;

	#[ORM\Column(type: 'string')]
	public string $argumentsJson;

	#[ORM\Column(type: 'string')]
	public string $targetClientId;

	#[ORM\Column(type: 'string')]
	public string $status;

	#[ORM\Column(type: 'datetime')]
	public DateTime $expiresAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $deliveredAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $answeredAt;

	#[ORM\Column(type: 'string')]
	public ?string $outcome;

	#[ORM\Column(type: 'string')]
	public ?string $answerText;

	#[ORM\Column(type: 'integer')]
	public ?int $answerChars;

	#[ORM\Column(type: 'string')]
	public ?string $answerDetailsJson;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBAIConversationModel::class)]
	#[ORM\JoinColumn(name: 'conversationId', referencedColumnName: 'id')]
	public DBAIConversationModel $conversation;

	#[ORM\ManyToOne(targetEntity: DBAccountModel::class)]
	#[ORM\JoinColumn(name: 'accountId', referencedColumnName: 'id')]
	public DBAccountModel $account;

}