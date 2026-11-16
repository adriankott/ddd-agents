<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The decision layer as the platform ran it during a suite run: its mode, the effective mode per decision site and
 * the turn-closing mode.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSuiteDecisionLayerSettings extends ValueObject
{
    /** @var string The decision layer's mode */
    public string $mode = '';

    /** @var string[] Every decision site → its effective mode */
    public array $siteModes = [];

    /** @var string The turn-closing mode */
    public string $turnClosingMode = '';

    /** The decision layer of the settings `$agentDecisionModelSettings`. */
    public static function fromDecisionModelSettings(AgentDecisionModelSettings $agentDecisionModelSettings): static
    {
        $agentEvalSuiteDecisionLayerSettings = static::newInstance();
        $agentEvalSuiteDecisionLayerSettings->mode = $agentDecisionModelSettings->mode;
        $agentEvalSuiteDecisionLayerSettings->siteModes = $agentDecisionModelSettings->siteModesSummary();
        $agentEvalSuiteDecisionLayerSettings->turnClosingMode = $agentDecisionModelSettings->turnClosingMode;
        return $agentEvalSuiteDecisionLayerSettings;
    }

    /**
     * The block a suite record stores under `settings.platformDecisionLayer`: part of the stored document of
     * `<suite run>/_suite.json`.
     *
     * @return array{mode: string, sites: string[], turnClosingMode: string}
     */
    public function toArray(): array
    {
        return ['mode' => $this->mode, 'sites' => $this->siteModes, 'turnClosingMode' => $this->turnClosingMode];
    }
}
