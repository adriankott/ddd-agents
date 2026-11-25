<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadTurnInputs;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentTurnStartSubSkillQuestions;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Skills\AdmittedAISkills;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use DDD\Domain\AI\Services\Conversations\AIConversationMessagesService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentSkillPreloadDecisionsService;
use Throwable;

/**
 * The skill-preload site with its request INPUTS given by the test (the offered catalog, the plan render and the turn's
 * texts are the row's) and its request builder, application and follow-up pass run as they are. An input is keyed
 * `roots` (the roots pass) or `<subject or pass<n>>:<unlocking skill>` (a sub-skill pass); a key without an input
 * answers null. The admission through the offer policy is the row's too: every picked skill is admitted (a world line),
 * inside the claim-fenced write of {@see UnfencedWriteMessagesServiceDouble}.
 */
class SkillPreloadServiceWithScriptedInputsDouble extends AgentSkillPreloadDecisionsService
{
    /** @var AgentSkillPreloadDecisionQuestion[] input key → the question the pass contributes */
    public array $scriptedInputs = [];

    /** @var AgentSkillPreloadDecisionQuestion[] loaded skill name → its sub-skill question (the turn-start children) */
    public array $scriptedLoadedSkillSubSkillInputs = [];

    /** True: {@see buildLoadedSkillSubSkillPreloadRequestInputs()} runs the package's method (its guards). */
    public bool $runsThePackagesLoadedSkillInputs = false;

    /** @var array<int, AgentRun|null> the run every call of this site was handed, in call order */
    public array $receivedAgentRuns = [];

    protected ?UnfencedWriteMessagesServiceDouble $messagesServiceDouble = null;

    /** @var array<string, Throwable> subject prefix (`roots`, `loadedSkill:`, `pass2:`, `modelLoad:`) → thrown by its application, after the log line */
    public array $applyFailureBySubjectPrefix = [];

    public function __construct(public DecisionSiteWorldLog $worldLog)
    {
    }

    public function buildSkillPreloadRequestInput(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings, array $assumeLoadedSkillNames = [], ?string $unlockedByRootSkillName = null, int $pass = 1, ?string $subSkillPassSubject = null, ?AgentRun $agentRun = null, ?AgentSkillPreloadTurnInputs $skillPreloadTurnInputs = null): ?AgentSkillPreloadDecisionQuestion
    {
        $this->receivedAgentRuns[] = $agentRun;
        $inputKey = $unlockedByRootSkillName === null ? 'roots' : ($subSkillPassSubject ?? 'pass' . $pass) . ':' . $unlockedByRootSkillName;
        $input = $this->scriptedInputs[$inputKey] ?? null;
        $this->worldLog->record('site build skillPreload ' . $inputKey . ' assumeLoaded=' . json_encode(array_values($assumeLoadedSkillNames)) . ' → ' . ($input === null ? 'null' : 'input'));
        return $input;
    }

    public function buildLoadedSkillSubSkillPreloadRequestInputs(AIConversation $conversation, AgentDecisionModelSettings $decisionModelSettings, ?AgentRun $agentRun = null, ?AgentSkillPreloadTurnInputs $skillPreloadTurnInputs = null): AgentTurnStartSubSkillQuestions
    {
        $this->receivedAgentRuns[] = $agentRun;
        if ($this->runsThePackagesLoadedSkillInputs) {
            return parent::buildLoadedSkillSubSkillPreloadRequestInputs($conversation, $decisionModelSettings, $agentRun, $skillPreloadTurnInputs);
        }
        $this->worldLog->record('site build skillPreload loadedSkillSubSkills → ' . json_encode(array_keys($this->scriptedLoadedSkillSubSkillInputs)));
        $subSkillQuestions = new AgentTurnStartSubSkillQuestions();
        foreach ($this->scriptedLoadedSkillSubSkillInputs as $loadedSkillName => $scriptedSubSkillQuestion) {
            $subSkillQuestions->addQuestionOfLoadedSkill((string)$loadedSkillName, $scriptedSubSkillQuestion);
        }
        return $subSkillQuestions;
    }

    public function buildSubSkillPreloadDecisionRequest(AIConversation $conversation, AgentSkillPreloadDecisionQuestion $subSkillQuestion, AgentDecisionModelSettings $decisionModelSettings): ArgusAgentTurnStartDecision
    {
        $this->worldLog->record('site build skillPreload subSkillRequest ' . (string)($subSkillQuestion->subject ?? ''));
        return parent::buildSubSkillPreloadDecisionRequest($conversation, $subSkillQuestion, $decisionModelSettings);
    }

    public function applySkillPreloadDecision(
        AIConversation $conversation,
        AgentSkillPreloadDecisionQuestion $skillPreloadQuestion,
        ?ArgusAgentTurnStartDecision $argusDecision,
        ?string $requestDecidedBy,
        AgentDecisionModelSettings $decisionModelSettings,
        ?string $runToken,
        string $requestId,
        ?int $elapsedMs,
        ?string $transportFailureReason,
        ?AgentRun $agentRun = null
    ): void {
        $this->receivedAgentRuns[] = $agentRun;
        $this->worldLog->record('site apply skillPreload ' . (string)($skillPreloadQuestion->subject ?? 'roots') . ' batchDecidedBy=' . ($requestDecidedBy ?? 'null') . ' request=' . ($argusDecision === null ? 'none' : 'sent') . ' failure=' . ($transportFailureReason ?? 'null'));
        foreach ($this->applyFailureBySubjectPrefix as $subjectPrefix => $applyFailure) {
            if (str_starts_with((string)($skillPreloadQuestion->subject ?? 'roots'), $subjectPrefix)) {
                throw $applyFailure;
            }
        }
        parent::applySkillPreloadDecision($conversation, $skillPreloadQuestion, $argusDecision, $requestDecidedBy, $decisionModelSettings, $runToken, $requestId, $elapsedMs, $transportFailureReason, $agentRun);
    }

    protected function promoteSkillsFromSource(AIConversation $conversation, array $skillNames, string $source, ?AIConversationMessage $anchorMessage = null, bool $dryRun = false, array $assumeLoadedSkillNames = [], ?AgentRun $agentRun = null): AdmittedAISkills
    {
        $this->receivedAgentRuns[] = $agentRun;
        $this->worldLog->record('site admission ' . json_encode(array_values($skillNames)) . ' source=' . $source . ' dryRun=' . json_encode($dryRun) . ' assumeLoaded=' . json_encode(array_values($assumeLoadedSkillNames)));
        $admittedAISkills = new AdmittedAISkills();
        $admittedAISkills->admittedSkillNames = array_values($skillNames);
        return $admittedAISkills;
    }

    protected function messagesService(): AIConversationMessagesService
    {
        if ($this->messagesServiceDouble === null) {
            $this->messagesServiceDouble = new UnfencedWriteMessagesServiceDouble($this->worldLog);
        }
        return $this->messagesServiceDouble;
    }
}
