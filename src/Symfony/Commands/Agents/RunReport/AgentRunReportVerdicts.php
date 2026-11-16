<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\RunReport;

use DDD\Domain\Base\Entities\ObjectSet;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The judge verdicts of evaluated runs ({@see AgentRunReportVerdict}), one per conversation. A later log that names the
 * same conversation replaces the verdict read before.
 *
 * A plain object of one printout; no container service.
 *
 * @property AgentRunReportVerdict[] $elements
 * @method AgentRunReportVerdict[] getElements()
 * @method AgentRunReportVerdict|null first()
 * @method AgentRunReportVerdict|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentRunReportVerdicts extends ObjectSet
{
    /**
     * @param int $aiConversationId
     * @return AgentRunReportVerdict|null the verdict of that conversation; null when no log names it
     */
    public function getByConversationId(int $aiConversationId): ?AgentRunReportVerdict
    {
        return $this->getByUniqueKey(AgentRunReportVerdict::uniqueKeyForConversationId($aiConversationId));
    }

    /**
     * Adds the verdict of a run, replacing one of the same conversation read before.
     *
     * @param AgentRunReportVerdict $runVerdict
     * @return void
     */
    public function addOrReplace(AgentRunReportVerdict $runVerdict): void
    {
        $verdictReadBefore = $this->getByConversationId($runVerdict->aiConversationId);
        if ($verdictReadBefore !== null) {
            $this->remove($verdictReadBefore);
        }
        $this->add($runVerdict);
    }
}
