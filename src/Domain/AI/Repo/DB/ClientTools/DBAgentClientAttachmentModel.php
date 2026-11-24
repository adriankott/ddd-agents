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
#[ORM\Table(name: 'AgentClientAttachments')]
class DBAgentClientAttachmentModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentClientAttachment';

	public const string TABLE_NAME = 'AgentClientAttachments';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\ClientTools\AgentClientAttachment';

	#[ORM\Column(type: 'integer')]
	public int $conversationId;

	#[ORM\Column(type: 'integer')]
	public int $accountId;

	#[ORM\Column(type: 'string')]
	public string $clientId;

	#[ORM\Column(type: 'datetime')]
	public DateTime $lastSeenAt;

	#[ORM\Column(type: 'boolean')]
	public ?bool $visible;

	#[ORM\Column(type: 'string')]
	public ?string $route;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $lastOwnerSendAt;

	#[ORM\Column(type: 'string')]
	public ?string $uiStateJson;

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