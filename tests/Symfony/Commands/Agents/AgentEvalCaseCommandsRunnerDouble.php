<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalNotApplicableReason;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOptions;
use DDD\Domain\AI\Entities\Evals\AgentEvalRunOutcome;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\AI\Services\Eval\AgentEvalRunnerService;
use DDD\Domain\Common\Entities\Accounts\Account;
use RuntimeException;

/**
 * The runner of the selection, recall and tiers commands' tests: no model call, no database. A case is not applicable
 * when its id is listed, a run throws when its prompt is listed, and every other run answers a conversation of one
 * tool call ({@see AgentEvalCaseCommandsConversationDouble}). It records the scope it was asked to resolve (the
 * case's scope keys and the fallback space), the prompts it ran and the settings of each run.
 */
class AgentEvalCaseCommandsRunnerDouble extends AgentEvalRunnerService
{
    /** @var string[] The ids of the cases that do not apply */
    public array $notApplicableCaseIds = [];

    /** @var string The detail of a case that does not apply */
    public string $notApplicableDetail = 'the seed of the case failed';

    /** @var string[] The prompts whose run throws */
    public array $throwingPrompts = [];

    /** @var string The tool every driven conversation calls */
    public string $calledToolName = 'listItems';

    /** @var string[] The skills every driven conversation loaded */
    public array $loadedSkillNames = [];

    /** @var int[] The conversations a recall run seeds */
    public array $seededConversationIds = [11, 12];

    /** @var string[] "<scope keys as JSON>|<fallback space>" of every scope resolution, in order */
    public array $resolvedScopes = [];

    /** @var string[] The prompts that ran, in order */
    public array $ranPrompts = [];

    /** @var string[] "<model>|persist=<0|1>|writesBlocked=<0|1>|keepSeed=<0|1>" of each run, in order */
    public array $runSettingsOfRuns = [];

    public function resolveScopeAndFindNotApplicableReasonForCase(AgentEvalCase $agentEvalCase, ?int $fallbackAgentSpaceId, AgentEvalRunOptions $agentEvalRunOptions): ?AgentEvalNotApplicableReason
    {
        if (in_array($agentEvalCase->getId(), $this->notApplicableCaseIds, true)) {
            return AgentEvalNotApplicableReason::buildPreconditionNotEstablishedReason($this->notApplicableDetail);
        }
        return null;
    }

    public function resolveEvalScope(AgentEvalCase $agentEvalCase, ?int $fallbackAgentSpaceId = null): AgentEvalScope
    {
        $caseFields = $agentEvalCase->toArray();
        unset($caseFields['prompt'], $caseFields['restore'], $caseFields['blockedTools'], $caseFields['allowExternalWrites'], $caseFields['id'], $caseFields['expect'], $caseFields['seedConversations'], $caseFields['expectMentions']);
        $this->resolvedScopes[] = json_encode($caseFields) . '|' . ($fallbackAgentSpaceId ?? '-');
        $agentSpaceId = $agentEvalCase->getAgentSpaceId() ?? $fallbackAgentSpaceId;
        if ($agentSpaceId === null && $caseFields === []) {
            throw new RuntimeException("The case '{$agentEvalCase->getId()}' names no agent space and the caller gave none.");
        }
        $agentSpace = AgentSpace::newInstance();
        $agentSpace->id = $agentSpaceId ?? 1;
        $ownerAccount = Account::newInstance();
        $ownerAccount->id = 5;
        return AgentEvalScope::forAgentSpace($agentSpace, $ownerAccount);
    }

    public function run(AgentEvalScope $agentEvalScope, AgentEvalCase $agentEvalCase, AgentEvalRunOptions $agentEvalRunOptions): AgentEvalRunOutcome
    {
        return AgentEvalRunOutcome::forConversation($this->driveScriptedConversation($agentEvalCase, $agentEvalRunOptions));
    }

    public function runCase(AgentEvalScope $agentEvalScope, AgentEvalCase $agentEvalCase, AgentEvalRunOptions $agentEvalRunOptions): AgentEvalRunOutcome
    {
        return $this->run($agentEvalScope, $agentEvalCase, $agentEvalRunOptions);
    }

    public function runCrossConversationRecallWithSeed(AgentEvalScope $agentEvalScope, AgentEvalCase $agentEvalCase, AgentEvalRunOptions $agentEvalRunOptions): AgentEvalRunOutcome
    {
        $agentEvalRunOptions->forgetSeededConversationIds();
        foreach ($this->seededConversationIds as $seededConversationId) {
            $agentEvalRunOptions->recordSeededConversationId($seededConversationId);
        }
        return AgentEvalRunOutcome::forConversation($this->driveScriptedConversation($agentEvalCase, $agentEvalRunOptions));
    }

    /** The trace of the test: one line per message, so a case file's trace is readable in an assertion. */
    public function buildMessagesTraceWithoutConversationBackReference(AIConversationMessages $messages): mixed
    {
        return ['elements' => array_map(
            static fn (AIConversationMessage $message): array => ['messageType' => $message->messageType, 'message' => $message->message],
            array_values($messages->getElements())
        )];
    }

    protected function driveScriptedConversation(AgentEvalCase $agentEvalCase, AgentEvalRunOptions $agentEvalRunOptions): AgentEvalCaseCommandsConversationDouble
    {
        $this->ranPrompts[] = $agentEvalCase->getPrompt();
        $this->runSettingsOfRuns[] = sprintf(
            '%s|persist=%d|writesBlocked=%d|keepSeed=%d',
            $agentEvalRunOptions->aiModelName ?? 'default',
            (int)$agentEvalRunOptions->persistConversations,
            (int)$agentEvalRunOptions->everyWriteToolBlocked,
            (int)$agentEvalRunOptions->keepSeededConversations
        );
        if (in_array($agentEvalCase->getPrompt(), $this->throwingPrompts, true)) {
            throw new RuntimeException('the model did not answer');
        }
        return AgentEvalCaseCommandsConversationDouble::forTurn(
            $agentEvalCase->getPrompt(),
            $this->calledToolName,
            AIConversationMessage::STATUS_COMPLETED,
            'Your invoice number is 4711.',
            $this->loadedSkillNames
        );
    }
}
