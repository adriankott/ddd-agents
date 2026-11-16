<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The skill-preload figures of a tools evaluation over every judged owner turn of the rows that carry a `skillPreload`
 * block: did the decision model preload the expected skill (hit), a wrong one, nothing (skip / not asked) — beside the
 * chat model's own first load.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSkillPreloadRollUp extends ValueObject
{
    /** @var string The skill-preload arm of the run */
    public string $arm = '';

    /** @var int The judged owner turns */
    public int $judgedTurns = 0;

    /** @var int Turns the decision model preloaded the expected skill on */
    public int $hits = 0;

    /** @var int Turns it preloaded another skill on */
    public int $wrongPicks = 0;

    /** @var int Turns it preloaded nothing on */
    public int $skips = 0;

    /** @var int Turns it was not asked on */
    public int $notAsked = 0;

    /** @var int Turns the chat model's own first load was the expected skill */
    public int $modelFirstLoadHits = 0;

    /** The figures of the rows `$preloadRows` (each carries a `skillPreload` block) under the arm `$skillPreloadArm`. */
    public static function fromRows(string $skillPreloadArm, AgentEvalCaseRows $preloadRows): static
    {
        $agentEvalSkillPreloadRollUp = static::newInstance();
        $agentEvalSkillPreloadRollUp->arm = $skillPreloadArm;
        foreach ($preloadRows->getElements() as $preloadRow) {
            $preloadBlock = (array)$preloadRow->getValue('skillPreload');
            $agentEvalSkillPreloadRollUp->judgedTurns += (int)($preloadBlock['judged'] ?? 0);
            $agentEvalSkillPreloadRollUp->hits += (int)($preloadBlock['hits'] ?? 0);
            $agentEvalSkillPreloadRollUp->wrongPicks += (int)($preloadBlock['wrongPicks'] ?? 0);
            $agentEvalSkillPreloadRollUp->skips += (int)($preloadBlock['skips'] ?? 0);
            $agentEvalSkillPreloadRollUp->notAsked += (int)($preloadBlock['notAsked'] ?? 0);
            $agentEvalSkillPreloadRollUp->modelFirstLoadHits += (int)($preloadBlock['modelFirstLoadHits'] ?? 0);
        }
        return $agentEvalSkillPreloadRollUp;
    }

    /** `$count` per judged turn, three decimals; null without a judged turn. */
    public function getRateOfJudgedTurns(int $count): ?float
    {
        return $this->judgedTurns > 0 ? round($count / $this->judgedTurns, 3) : null;
    }

    /**
     * The figures as a tools summary stores them under `metrics.skillPreload`: part of the stored document of
     * `<run>.json` / `<run>/_summary.json`.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'arm' => $this->arm,
            'judgedTurns' => $this->judgedTurns,
            'hits' => $this->hits,
            'wrongPicks' => $this->wrongPicks,
            'skips' => $this->skips,
            'notAsked' => $this->notAsked,
            'hitRate' => $this->getRateOfJudgedTurns($this->hits),
            'wrongPickRate' => $this->getRateOfJudgedTurns($this->wrongPicks),
            'modelFirstLoadHits' => $this->modelFirstLoadHits,
            'modelFirstLoadHitRate' => $this->getRateOfJudgedTurns($this->modelFirstLoadHits),
        ];
    }
}
