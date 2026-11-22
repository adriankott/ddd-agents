<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The files a case puts into its own workspace before the agent runs ({@see AgentEvalScratchFile}), one per path, in
 * the order of the fixture's `scratchFiles`.
 *
 * @property AgentEvalScratchFile[] $elements
 * @method AgentEvalScratchFile[] getElements()
 * @method AgentEvalScratchFile|null first()
 * @method AgentEvalScratchFile|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AgentEvalScratchFiles extends ObjectSet
{
    /** The files of a case's `scratchFiles` list; entries without a path are left out. */
    public static function fromFixtureEntries(mixed $fixtureEntries): static
    {
        $agentEvalScratchFiles = static::newInstance();
        if (!is_array($fixtureEntries)) {
            return $agentEvalScratchFiles;
        }
        foreach ($fixtureEntries as $fixtureEntry) {
            $agentEvalScratchFile = AgentEvalScratchFile::fromFixtureEntry($fixtureEntry);
            if ($agentEvalScratchFile !== null) {
                $agentEvalScratchFiles->add($agentEvalScratchFile);
            }
        }
        return $agentEvalScratchFiles;
    }
}
