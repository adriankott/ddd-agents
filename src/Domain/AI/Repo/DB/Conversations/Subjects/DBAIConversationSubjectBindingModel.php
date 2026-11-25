<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Conversations\Subjects;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AIConversationSubjectBindings')]
class DBAIConversationSubjectBindingModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AIConversationSubjectBinding';

	public const string TABLE_NAME = 'AIConversationSubjectBindings';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Conversations\Subjects\AIConversationSubjectBinding';

	#[ORM\Column(type: 'integer')]
	public int $aiConversationId;

	#[ORM\Column(type: 'integer')]
	public int $agentSpaceId;

	#[ORM\Column(type: 'string')]
	public string $purposeName;

	#[ORM\Column(type: 'string')]
	public string $subjectEntityClass;

	#[ORM\Column(type: 'integer')]
	public int $subjectEntityId;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\ManyToOne(targetEntity: DBAIConversationModel::class)]
	#[ORM\JoinColumn(name: 'aiConversationId', referencedColumnName: 'id')]
	public DBAIConversationModel $aiConversation;

}