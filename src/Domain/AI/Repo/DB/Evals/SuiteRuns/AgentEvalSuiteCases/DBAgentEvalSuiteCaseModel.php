<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\AgentEvalSuiteCases;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\DBAgentEvalSuiteRunModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AgentEvalSuiteCases')]
class DBAgentEvalSuiteCaseModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentEvalSuiteCase';

	public const string TABLE_NAME = 'AgentEvalSuiteCases';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteCases\AgentEvalSuiteCase';

	#[ORM\Column(type: 'integer')]
	public int $agentEvalSuiteRunId;

	#[ORM\Column(type: 'string')]
	public string $caseId;

	#[ORM\Column(type: 'string')]
	public ?string $suiteName;

	#[ORM\Column(type: 'string')]
	public ?string $fixtureName;

	#[ORM\Column(type: 'string')]
	public ?string $modelName;

	#[ORM\Column(type: 'string')]
	public ?string $flavor;

	#[ORM\Column(type: 'integer')]
	public ?int $aiConversationId;

	#[ORM\Column(type: 'string')]
	public string $verdict;

	#[ORM\Column(type: 'string')]
	public ?string $findings;

	#[ORM\Column(type: 'float')]
	public ?float $wallSeconds;

	#[ORM\Column(type: 'float')]
	public ?float $agentSeconds;

	#[ORM\Column(type: 'integer')]
	public ?int $ownerTurns;

	#[ORM\Column(type: 'integer')]
	public ?int $modelCalls;

	#[ORM\Column(type: 'integer')]
	public ?int $toolCalls;

	#[ORM\Column(type: 'float')]
	public ?float $costUsd;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\ManyToOne(targetEntity: DBAgentEvalSuiteRunModel::class)]
	#[ORM\JoinColumn(name: 'agentEvalSuiteRunId', referencedColumnName: 'id')]
	public DBAgentEvalSuiteRunModel $agentEvalSuiteRun;

}