<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The settings of a suite run as its record states them: the models, flavors and suites it ran, the arms, whether the
 * conversations were stored, the process count, whether a write isolated its whole scope, the reasoning effort each
 * model is configured with, and the decision layer as the platform ran it.
 *
 * Built with `AgentEvalSuiteRecordSettings::newInstance()`, which gives the application's class of the same path when
 * it has one. An application that names the scope with its own word redeclares {@see ISOLATE_WHOLE_SCOPE_KEY}; one
 * that records more settings adds them on its class and extends {@see toArray()}.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSuiteRecordSettings extends ValueObject
{
    /** @var string The key under which the record stores {@see $isolateWholeScope} */
    public const string ISOLATE_WHOLE_SCOPE_KEY = 'isolateWholeScope';

    /** @var string[] The models the run ran */
    public array $modelNames = [];

    /** @var string[] The flavors the run ran */
    public array $flavors = [];

    /** @var string[] The suites the run ran */
    public array $suites = [];

    /** @var string[] The arms of the run: option name → value */
    public array $arms = [];

    /** @var bool Whether the run's conversations were stored (a transient run runs no decision site) */
    public bool $persistedConversations = true;

    /** @var int The most child processes at a time */
    public int $maxProcesses = 0;

    /** @var bool Whether a writing task isolated its whole scope (every read of the scope waits) */
    public bool $isolateWholeScope = false;

    /** @var array<string, string|null> Every model → the reasoning effort it is configured with (null: none) */
    public array $configuredReasoningEffortByModel = [];

    /** @var AgentEvalSuiteDecisionLayerSettings The decision layer as the platform ran it */
    public AgentEvalSuiteDecisionLayerSettings $decisionLayer;

    /**
     * The settings of a run under the decision layer `$agentEvalSuiteDecisionLayerSettings`; the caller sets the rest.
     */
    public static function forDecisionLayer(AgentEvalSuiteDecisionLayerSettings $agentEvalSuiteDecisionLayerSettings): static
    {
        $agentEvalSuiteRecordSettings = static::newInstance();
        $agentEvalSuiteRecordSettings->decisionLayer = $agentEvalSuiteDecisionLayerSettings;
        return $agentEvalSuiteRecordSettings;
    }

    /**
     * The settings as a suite record stores them under `settings`: part of the stored document of
     * `<suite run>/_suite.json`.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'models' => $this->modelNames,
            'flavors' => $this->flavors,
            'suites' => $this->suites,
            'arms' => $this->arms,
            'persistedConversations' => $this->persistedConversations,
            'maxProcesses' => $this->maxProcesses,
            static::ISOLATE_WHOLE_SCOPE_KEY => $this->isolateWholeScope,
            'configuredReasoningEffortByModel' => $this->configuredReasoningEffortByModel,
            'platformDecisionLayer' => $this->decisionLayer->toArray(),
        ];
    }
}
