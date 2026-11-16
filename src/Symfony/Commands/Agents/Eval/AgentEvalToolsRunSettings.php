<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Evals\AgentEvalArms;
use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteTask;
use DDD\Domain\Base\Entities\ValueObject;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * What one run of the tools evaluation was asked for, read once from the command line: the model and the flavor, the
 * judge's and the driver's model, the filters, the output switches, the repetitions and the process slots, the arms,
 * whether the conversations are stored, the simulated owner device, the fallback agent space, whether the model can
 * see images, and the name of the run in the results folder.
 *
 * A run whose cases repeat, or that runs more than one case in parallel without a case filter, runs every case in a
 * child process of its own ({@see runsInChildProcesses()}); every other run drives its cases in this process.
 *
 * A plain object of one command run; no container service, never stored.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentEvalToolsRunSettings extends ValueObject
{
    /** @var string|null The agent model under test; null = the conversation default */
    public ?string $modelName = null;

    /** @var string The flavor of the run ({@see AgentEvalSuiteTask::FLAVOR_CALL} or {@see AgentEvalSuiteTask::FLAVOR_JUDGE}) */
    public string $flavor = AgentEvalSuiteTask::FLAVOR_CALL;

    /** @var string|null The judge's model of the judged flavor; null = the judged run's default */
    public ?string $judgeModelName = null;

    /** @var string|null The driver's model of the judged flavor; null = the judged run's default */
    public ?string $driverModelName = null;

    /** @var string|null Only the fixture whose skill is this name */
    public ?string $skillFilter = null;

    /** @var string|null Only the case with this id */
    public ?string $caseFilter = null;

    /** @var string|null The one fixture file the run reads; null = the configured fixture folder */
    public ?string $fixtureOption = null;

    /** @var bool Whether passing cases print their tool calls and final text too */
    public bool $fullOutput = false;

    /** @var bool Whether the run prints its result document instead of the report */
    public bool $jsonOutput = false;

    /** @var bool Whether the run writes no file of its own into the results folder and stores no record */
    public bool $noSave = false;

    /** @var bool Whether the retention sweep of the results folder is skipped */
    public bool $noPrune = false;

    /** @var int How often every case runs */
    public int $repeat = 1;

    /** @var int How many child processes run at the same time */
    public int $parallel = 1;

    /** @var string|null The file the whole result is also written to (the file a parent reads); null = none */
    public ?string $resultFile = null;

    /** @var bool Whether the cases whose flow exists only on the job queue run inline too */
    public bool $includeAsyncOnlyCases = false;

    /** @var bool Whether the run's conversations are stored */
    public bool $persistConversations = true;

    /** @var string The surface name of the simulated owner device as given; empty = none */
    public string $ownerDeviceSurface = '';

    /** @var OwnerDevice|null The device every owner message of the run carries; null = none */
    public ?OwnerDevice $simulatedOwnerDevice = null;

    /** @var string[] The arms of the run: option name → value */
    public array $evalArms = [];

    /** @var AgentEvalArms The arms of the run as the conversations are built with them */
    public AgentEvalArms $agentEvalArms;

    /** @var int|null The agent space of the cases that name none; null = such a case is skipped */
    public ?int $fallbackAgentSpaceId = null;

    /** @var bool Whether the model under test can see images (the default model cannot) */
    public bool $modelCanSeeImages = false;

    /** @var int When the run started (Unix time): the date and time in its name */
    public int $startedAtTimestamp = 0;

    /** @var string The scope part of the run's name and the label of its stored record */
    public string $scopeTag = '';

    /** @var string The name of the run in the results folder */
    public string $runName = '';

    /** Whether the run is of the judged flavor. */
    public function isJudged(): bool
    {
        return $this->flavor === AgentEvalSuiteTask::FLAVOR_JUDGE;
    }

    /**
     * Whether every case runs in a child process of its own: the cases repeat, or more than one runs at a time and no
     * case filter makes the run a single case's.
     */
    public function runsInChildProcesses(): bool
    {
        return $this->repeat > 1 || ($this->parallel > 1 && $this->caseFilter === null);
    }

    /** Whether the run keeps a record in the database: it writes its own files and no parent reads its result. */
    public function storesRecord(): bool
    {
        return !$this->noSave && $this->resultFile === null;
    }

    /** The plan-dynamics arm of the run, `$defaultArmName` when the run chose none. */
    public function getPlanDynamicsArm(string $defaultArmName): string
    {
        return $this->agentEvalArms->planDynamicsArm ?? $defaultArmName;
    }

    /** The skill-preload arm of the run, `$defaultArmName` when the run chose none. */
    public function getSkillPreloadArm(string $defaultArmName): string
    {
        return $this->agentEvalArms->skillPreloadRow ?? $defaultArmName;
    }
}
