<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\Guard\AgentEvalGuardRunKnobs;
use DDD\Domain\AI\Repo\Argus\Guard\ArgusAgentSentinelCanaryGuard;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Symfony\Commands\Agents\AgentEvalGuardCommand;
use ReflectionClass;
use Symfony\Component\Console\Input\InputInterface;

/**
 * The guard probe command on scripted answers: the fixtures are the test's, the scope is one without a space, every
 * guard answers the next scripted raw text, envelopes and the run file are recorded instead of written. The command
 * line of a child is callable from a test.
 */
class AgentEvalGuardCommandWithScriptedProbes extends AgentEvalGuardCommand
{
    public const int OWNER_ACCOUNT_ID = 5;

    public const string COMMAND_NAME = 'app:ai-agent:eval-guard';

    /** @var string[] The fixture files of the run */
    public array $fixturePaths = [];

    /** @var string[] The raw answers the guards give, in order */
    public array $scriptedRawResponses = [];

    /** @var SentinelCanaryGuardWithScriptedAnswerDouble[] The guards built, in order */
    public array $builtGuards = [];

    public int $scopeResolutions = 0;

    public function __construct(
        public RecordingUsageEnvelopesServiceDouble $usageEnvelopesServiceDouble,
        public ResultFilesServiceRecordingLooseRunFilesDouble $resultFilesServiceDouble
    ) {
        // the attribute of the command's name is the module class's; a subclass without one names itself
        parent::__construct(self::COMMAND_NAME);
    }

    /**
     * @param string[] $chunkCellKeys
     * @return string[]
     */
    public function buildChildProcessArgumentsOf(array $chunkCellKeys, AgentEvalGuardRunKnobs $agentEvalGuardRunKnobs, ?string $modelName, InputInterface $input): array
    {
        return $this->buildChildProcessArguments($chunkCellKeys, $agentEvalGuardRunKnobs, $modelName, $input);
    }

    protected function findFixturePaths(AgentEvalGuardRunKnobs $agentEvalGuardRunKnobs): array
    {
        return $this->fixturePaths;
    }

    protected function resolveScopeOfRun(InputInterface $input): AgentEvalScope
    {
        $this->scopeResolutions++;
        $ownerAccount = (new ReflectionClass(Account::class))->newInstanceWithoutConstructor();
        $ownerAccount->id = self::OWNER_ACCOUNT_ID;
        return AgentEvalScope::forAgentSpace(null, $ownerAccount);
    }

    protected function createSentinelCanaryGuard(): ArgusAgentSentinelCanaryGuard
    {
        $sentinelCanaryGuard = new SentinelCanaryGuardWithScriptedAnswerDouble();
        $sentinelCanaryGuard->scriptedRawResponse = (string)array_shift($this->scriptedRawResponses);
        $this->builtGuards[] = $sentinelCanaryGuard;
        return $sentinelCanaryGuard;
    }

    protected function getUsageEnvelopesService(): AIUsageEnvelopesService
    {
        return $this->usageEnvelopesServiceDouble;
    }

    protected function getAgentEvalResultFilesService(): AgentEvalResultFilesService
    {
        return $this->resultFilesServiceDouble;
    }
}
