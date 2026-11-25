<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Voice\Calls;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'EntityAgentVoiceCalls')]
class DBAgentVoiceCallModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentVoiceCall';

	public const string TABLE_NAME = 'EntityAgentVoiceCalls';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall';

	#[ORM\Column(type: 'string')]
	public ?string $providerCallId;

	#[ORM\Column(type: 'string')]
	public ?string $voiceProvider;

	#[ORM\Column(type: 'integer')]
	public ?int $accountId;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $ringDeadlineAt;

	#[ORM\Column(type: 'string')]
	public ?string $direction = 'INBOUND';

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $startedAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $endedAt;

	#[ORM\Column(type: 'string')]
	public ?string $endReason;

	#[ORM\Column(type: 'string')]
	public ?string $callTask;

	#[ORM\Column(type: 'integer')]
	public ?int $aiConversationId;

	#[ORM\Column(type: 'integer')]
	public ?int $startedByAgentInConversationId;

	#[ORM\Column(type: 'integer')]
	public ?int $bookedSeconds = 0;

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
	public ?DBAIConversationModel $aiConversation;
}
