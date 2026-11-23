<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Entities\Evals\AgentEvalScratchFiles;
use DDD\Domain\AI\Interfaces\AIConversationProgressReporterInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A module that keeps state per eval workspace (e.g. a drive whose own space is the workspace): the runner calls every
 * participant beside the application's {@see AgentEvalRunHooksService::prepareWorkspaceOfCase()} and
 * {@see AgentEvalRunHooksService::cleanUpWorkspaceOfCase()}, so a module isolates its cases without code in each
 * application. Registered by this tag (autoconfigured). Never fails the case: a throw is reported as one line.
 */
#[AutoconfigureTag(AgentEvalWorkspaceParticipant::TAG)]
interface AgentEvalWorkspaceParticipant
{
    public const string TAG = 'ai.agent_eval_workspace_participant';

    /**
     * Prepares the module's part of the case's workspace `$workspaceKey`, seeded with the case's files.
     *
     * @return string[] what was prepared, one line each
     */
    public function prepareWorkspaceOfCase(
        AgentEvalScope $agentEvalScope,
        string $workspaceKey,
        AgentEvalScratchFiles $agentEvalScratchFiles,
        AIConversationProgressReporterInterface $progressReporter
    ): array;

    /**
     * Removes the module's part of the case's workspace.
     *
     * @return string[] what was removed, one line each
     */
    public function cleanUpWorkspaceOfCase(string $workspaceKey, AIConversationProgressReporterInterface $progressReporter): array;
}
