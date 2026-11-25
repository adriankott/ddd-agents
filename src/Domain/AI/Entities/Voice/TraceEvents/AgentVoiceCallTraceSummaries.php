<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\TraceEvents;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The call headers of one conversation's voice calls, oldest first ({@see AgentVoiceCallTraceSummary}).
 *
 * @property AgentVoiceCallTraceSummary[] $elements
 * @method AgentVoiceCallTraceSummary[] getElements()
 * @method AgentVoiceCallTraceSummary|null first()
 */
class AgentVoiceCallTraceSummaries extends ObjectSet
{
}
