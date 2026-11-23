<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalScratchFiles;
use DDD\Domain\AI\Interfaces\AIConversationProgressReporterInterface;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/**
 * The modules that keep state per eval workspace ({@see AgentEvalWorkspaceParticipant}), called by the eval runner in
 * registration order. A participant that throws is reported as an `[ERROR]` line; the others still run.
 */
class AgentEvalWorkspaceParticipantsService extends Service
{
    /** @var iterable<AgentEvalWorkspaceParticipant> */
    protected iterable $participants = [];

    /** @param iterable<AgentEvalWorkspaceParticipant> $participants */
    #[Required]
    public function setParticipants(
        #[AutowireIterator(AgentEvalWorkspaceParticipant::TAG)]
        iterable $participants
    ): void {
        $this->participants = $participants;
    }

    /** @return string[] */
    public function prepareWorkspaceOfCase(
        AgentEvalScope $agentEvalScope,
        string $workspaceKey,
        AgentEvalScratchFiles $agentEvalScratchFiles,
        AIConversationProgressReporterInterface $progressReporter
    ): array {
        $preparedLines = [];
        foreach ($this->participants as $participant) {
            try {
                $preparedLines = [...$preparedLines, ...$participant->prepareWorkspaceOfCase($agentEvalScope, $workspaceKey, $agentEvalScratchFiles, $progressReporter)];
            } catch (Throwable $throwable) {
                $preparedLines[] = '[ERROR] ' . $participant::class . ': ' . $throwable->getMessage();
            }
        }
        return $preparedLines;
    }

    /** @return string[] */
    public function cleanUpWorkspaceOfCase(string $workspaceKey, AIConversationProgressReporterInterface $progressReporter): array
    {
        $removedLines = [];
        foreach ($this->participants as $participant) {
            try {
                $removedLines = [...$removedLines, ...$participant->cleanUpWorkspaceOfCase($workspaceKey, $progressReporter)];
            } catch (Throwable $throwable) {
                $removedLines[] = '[ERROR] ' . $participant::class . ': ' . $throwable->getMessage();
            }
        }
        return $removedLines;
    }
}
