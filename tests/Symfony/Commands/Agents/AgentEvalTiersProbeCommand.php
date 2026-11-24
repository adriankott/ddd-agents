<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\AIModelsService;
use DDD\Symfony\Commands\Agents\AgentEvalTiersCommand;

/**
 * The tiers command on the test's services: no model call, no database; the model catalog selects the test's model
 * per tier ({@see AgentEvalTiersModelsServiceDouble}).
 */
class AgentEvalTiersProbeCommand extends AgentEvalTiersCommand
{
    use AgentEvalCaseCommandServicesTrait;

    /** @var string The name the command runs under (a probe class carries no command attribute of its own) */
    public const string PROBE_COMMAND_NAME = 'app:agent:eval-tiers';

    public AgentEvalTiersModelsServiceDouble $modelsServiceDouble;

    public function __construct()
    {
        $this->setServiceDoubles();
        $this->modelsServiceDouble = new AgentEvalTiersModelsServiceDouble();
        parent::__construct(self::PROBE_COMMAND_NAME);
    }

    protected function getAIModelsService(): AIModelsService
    {
        return $this->modelsServiceDouble;
    }
}
