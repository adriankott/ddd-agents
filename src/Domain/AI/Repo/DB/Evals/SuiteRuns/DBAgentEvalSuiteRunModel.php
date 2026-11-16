<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Evals\SuiteRuns;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\AgentEvalSuiteCases\DBAgentEvalSuiteCaseModel;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AgentEvalSuiteRuns')]
class DBAgentEvalSuiteRunModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AgentEvalSuiteRun';

	public const string TABLE_NAME = 'AgentEvalSuiteRuns';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteRun';

	#[ORM\Column(type: 'string')]
	public ?string $suiteLabel;

	#[ORM\Column(type: 'string')]
	public string $command;

	#[ORM\Column(type: 'string')]
	public ?string $host;

	#[ORM\Column(type: 'datetime')]
	public DateTime $startedAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $finishedAt;

	#[ORM\Column(type: 'integer')]
	public ?int $casesTotal = 0;

	#[ORM\Column(type: 'integer')]
	public ?int $casesPassed = 0;

	#[ORM\Column(type: 'integer')]
	public ?int $casesFailed = 0;

	#[ORM\Column(type: 'integer')]
	public ?int $casesErrored = 0;

	#[ORM\Column(type: 'integer')]
	public ?int $casesNotApplicable = 0;

	#[ORM\Column(type: 'integer')]
	public ?int $casesFailedAsExpected = 0;

	#[ORM\Column(type: 'string')]
	public ?string $runOptionsJson;

	#[ORM\Column(type: 'string')]
	public ?string $logDirectoryName;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

	#[ORM\OneToMany(targetEntity: DBAgentEvalSuiteCaseModel::class, mappedBy: 'agentEvalSuiteRun')]
	public PersistentCollection $agentEvalSuiteCases;

}