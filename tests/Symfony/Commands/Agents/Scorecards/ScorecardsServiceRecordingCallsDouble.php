<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Scorecards;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Quality\AgentConversationScorecardSelection;
use DDD\Domain\AI\Services\Conversations\Quality\AgentConversationScorecardsService;

/**
 * The scorecards service recording the selections it is asked for and the scorecards it is asked to write; it answers
 * a selection with the scripted ids and a write with the scripted analysis outcome. Reads and writes nothing.
 */
class ScorecardsServiceRecordingCallsDouble extends AgentConversationScorecardsService
{
    /** @var int[] The ids every selection answers with */
    public array $selectedConversationIds = [];

    /** @var AgentConversationScorecardSelection[] The selections asked for, in order */
    public array $askedSelections = [];

    /** @var array<int, bool> The conversations whose scorecard was written, with the force flag, by id in order */
    public array $forceAnalysisByWrittenConversationId = [];

    /** @var string|null The analysis outcome every write answers with */
    public ?string $analysisOutcome = 'done';

    public function findConversationIdsForSelection(AgentConversationScorecardSelection $selection): array
    {
        $this->askedSelections[] = $selection;
        return $this->selectedConversationIds;
    }

    public function writeScorecardOfFinalizedConversation(AIConversation $conversation, bool $forceAnalysis): ?string
    {
        $this->forceAnalysisByWrittenConversationId[(int)$conversation->id] = $forceAnalysis;
        return $this->analysisOutcome;
    }
}
