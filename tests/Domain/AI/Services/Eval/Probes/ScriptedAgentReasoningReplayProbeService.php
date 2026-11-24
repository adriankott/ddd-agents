<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval\Probes;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Services\Eval\Probes\AgentReasoningReplayProbeService;
use DDD\Domain\AI\Services\Eval\Probes\AgentReasoningReplayProbeTransportService;

/** The probe with a scripted transport and its own catalog models. */
class ScriptedAgentReasoningReplayProbeService extends AgentReasoningReplayProbeService
{
    public ScriptedReasoningReplayProbeTransportService $scriptedTransport;

    /** @var AIModel[] The models the probe knows, by name */
    public array $modelsByName = [];

    protected function getTransportService(): AgentReasoningReplayProbeTransportService
    {
        return $this->scriptedTransport;
    }

    protected function findModel(string $modelName): ?AIModel
    {
        return $this->modelsByName[$modelName] ?? null;
    }
}
