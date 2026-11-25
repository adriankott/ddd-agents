<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentPlanDynamicsDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReasoningEffortDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecision;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentReplyLanguageDecisionQuestion;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadDecisionQuestion;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentSkillPreloadLoadedSkills;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Services\AIModelCallGateService;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Conversations\TurnEvents\AIConversationTurnEventsService;
use DDD\Domain\AI\Services\Decisions\AgentDecisionModelCircuitBreakerService;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecording;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The stage of the decision-site outcome tables: every decision call of a site runs the real Argus path up to the HTTP
 * client, whose handler is {@see DecisionModelNetworkDouble}; the breaker, the working-state writer, the turn-events
 * table and the model-call gate are container doubles writing one ordered {@see DecisionSiteWorldLog}. No database, no
 * network.
 *
 * The outcomes a row scripts:
 * - `usable` — HTTP 200, every question answered;
 * - `unparsable` — HTTP 200 with an empty `answers` object;
 * - `http400` — our own request is wrong (a known status outside the transport list);
 * - `transport` — HTTP 503 (a status of the transport list);
 * - `timeout` — the call timed out (a rejected child of the settled batch);
 * - `breakerOpen` — the shared breaker is open before the call;
 * - `refused` — our own gate refuses while the payload is built (a missing usage envelope);
 * - `error` — our own code fails while the payload is built (the OpenRouter key is missing).
 */
abstract class DecisionSiteOutcomeTestCase extends IntegrationTestCase
{
    public const string OUTCOME_USABLE = 'usable';

    public const string OUTCOME_UNPARSABLE = 'unparsable';

    public const string OUTCOME_HTTP_400 = 'http400';

    public const string OUTCOME_TRANSPORT = 'transport';

    public const string OUTCOME_TIMEOUT = 'timeout';

    public const string OUTCOME_BREAKER_OPEN = 'breakerOpen';

    public const string OUTCOME_REFUSED = 'refused';

    public const string OUTCOME_ERROR = 'error';

    /** @var string[] every outcome, in table order */
    public const array OUTCOMES = [
        self::OUTCOME_USABLE,
        self::OUTCOME_UNPARSABLE,
        self::OUTCOME_HTTP_400,
        self::OUTCOME_TRANSPORT,
        self::OUTCOME_TIMEOUT,
        self::OUTCOME_BREAKER_OPEN,
        self::OUTCOME_REFUSED,
        self::OUTCOME_ERROR,
    ];

    public const int CONVERSATION_ID = 770001;

    public const string OPENROUTER_KEY_VARIABLE = 'OPENROUTER_KEY';

    /** The owner message of the row's turn: at least ten words. */
    public const string OWNER_MESSAGE = 'Yes, please go ahead like that, and afterwards also look after the opening hours of the shop.';

    /** The assistant text before it. */
    public const string ASSISTANT_TEXT_BEFORE = 'I read the profile of the shop: the name, the address and the opening hours are set. Next I would add the '
        . 'services, check the photos and then pick the search terms customers use. Shall I start with the services?';

    public DecisionSiteWorldLog $worldLog;

    public DecisionModelNetworkDouble $decisionModelDouble;

    public RecordingCircuitBreakerDouble $circuitBreakerDouble;

    public WorkingStateSliceRecordingConversationsServiceDouble $conversationsServiceDouble;

    public RecordingTurnEventsServiceDouble $turnEventsServiceDouble;

    public ScriptedModelCallGateDouble $modelCallGateDouble;

    protected mixed $openRouterKeyBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worldLog = new DecisionSiteWorldLog();
        $this->decisionModelDouble = new DecisionModelNetworkDouble($this->worldLog);
        // a usable answer: the owner writes German and asks for no language, the gate passes the message, no injection
        $this->decisionModelDouble->answersByQuestion = [
            'written_language' => 'de',
            'requested_language' => 'none',
            'directive_language' => 'none',
            'gate_passes_message' => 0.97,
            'injection_present' => 0.05,
        ];
        $this->circuitBreakerDouble = new RecordingCircuitBreakerDouble($this->worldLog);
        $this->conversationsServiceDouble = new WorkingStateSliceRecordingConversationsServiceDouble($this->worldLog);
        $this->turnEventsServiceDouble = new RecordingTurnEventsServiceDouble($this->worldLog);
        $this->modelCallGateDouble = new ScriptedModelCallGateDouble();
        $this->replaceService(AgentDecisionModelCircuitBreakerService::class, $this->circuitBreakerDouble);
        $this->replaceService(AIConversationsService::class, $this->conversationsServiceDouble);
        $this->replaceService(AIConversationTurnEventsService::class, $this->turnEventsServiceDouble);
        $this->replaceService(AIModelCallGateService::class, $this->modelCallGateDouble);
        StaticRegistry::$entityServices = [];
        ArgusApiOperations::$transportRecording = ArgusTransportRecording::forRecording($this->decisionModelDouble);
        $this->openRouterKeyBefore = $_ENV[self::OPENROUTER_KEY_VARIABLE] ?? null;
        $this->setOpenRouterKey('test-not-a-secret');
        AgentDecisionModelSettings::useForCurrentProcess(AgentDecisionModelSettings::fromConfigured(self::allSitesOnConfiguration()));
    }

    protected function onTearDown(): void
    {
        ArgusApiOperations::resetForNewJob();
        ArgusApiOperations::$transportRecording = null;
        AgentDecisionModelSettings::useForCurrentProcess(null);
        $this->setOpenRouterKey(is_string($this->openRouterKeyBefore) ? $this->openRouterKeyBefore : null);
    }

    /** @return array<string, mixed> the shape of `decisionModel.php` with every package site `on` (plan dynamics: asked and received) */
    public static function allSitesOnConfiguration(): array
    {
        return [
            'mode' => 'on',
            'timeoutSeconds' => 2.0,
            'breakerSeconds' => 60,
            'turnClosingMode' => AgentDecisionModelSettings::TURN_CLOSING_MODE_DECISION_MODEL,
            'sites' => [
                AgentDecisionModelSettings::SITE_REPLY_LANGUAGE => 'on',
                AgentDecisionModelSettings::SITE_SKILL_PRELOAD => 'on',
                AgentDecisionModelSettings::SITE_PLAN_ITEM => 'on',
                AgentDecisionModelSettings::SITE_TURN_CLOSING => 'on',
                AgentDecisionModelSettings::SITE_REASONING_EFFORT => 'on',
                AgentDecisionModelSettings::SITE_STALL_RECOVERY => 'on',
                AgentDecisionModelSettings::SITE_OWNER_MESSAGE_INJECTION => 'on',
                AgentDecisionModelSettings::SITE_TOOL_RESULT_INJECTION => 'on',
                AgentDecisionModelSettings::SITE_OWNER_CONSENT => 'on',
                AgentDecisionModelSettings::SITE_PLAN_DYNAMICS => [
                    AgentDecisionModelSettings::PLAN_DYNAMICS_ASK_DECISION_MODEL => true,
                    AgentDecisionModelSettings::PLAN_DYNAMICS_MODEL_RECEIVES_VERDICT => true,
                ],
            ],
        ];
    }

    /** One site switched to `shadow`, every other site as {@see allSitesOnConfiguration()}. */
    protected function useSiteInShadow(string $site): void
    {
        $configured = self::allSitesOnConfiguration();
        if ($site === AgentDecisionModelSettings::SITE_PLAN_DYNAMICS) {
            $configured['sites'][$site][AgentDecisionModelSettings::PLAN_DYNAMICS_MODEL_RECEIVES_VERDICT] = false;
        } else {
            $configured['sites'][$site] = AgentDecisionModelSettings::MODE_SHADOW;
        }
        AgentDecisionModelSettings::useForCurrentProcess(AgentDecisionModelSettings::fromConfigured($configured));
    }

    /** Script the decision model, the breaker, our gate or our code for the outcome of a row. */
    protected function scriptOutcome(string $outcome): void
    {
        match ($outcome) {
            self::OUTCOME_USABLE => $this->decisionModelDouble->defaultMode = DecisionModelNetworkDouble::MODE_USABLE,
            self::OUTCOME_UNPARSABLE => $this->decisionModelDouble->defaultMode = DecisionModelNetworkDouble::MODE_UNPARSABLE,
            self::OUTCOME_HTTP_400 => $this->decisionModelDouble->defaultMode = DecisionModelNetworkDouble::MODE_HTTP_400,
            self::OUTCOME_TRANSPORT => $this->decisionModelDouble->defaultMode = DecisionModelNetworkDouble::MODE_HTTP_503,
            self::OUTCOME_TIMEOUT => $this->decisionModelDouble->defaultMode = DecisionModelNetworkDouble::MODE_TIMEOUT,
            self::OUTCOME_BREAKER_OPEN => $this->circuitBreakerDouble->open = true,
            self::OUTCOME_REFUSED => $this->modelCallGateDouble->refusesForAMissingEnvelope = true,
            self::OUTCOME_ERROR => $this->setOpenRouterKey(null),
        };
    }

    protected function setOpenRouterKey(?string $openRouterKey): void
    {
        if ($openRouterKey === null) {
            unset($_ENV[self::OPENROUTER_KEY_VARIABLE], $_SERVER[self::OPENROUTER_KEY_VARIABLE]);
            putenv(self::OPENROUTER_KEY_VARIABLE);
            return;
        }
        $_ENV[self::OPENROUTER_KEY_VARIABLE] = $openRouterKey;
        $_SERVER[self::OPENROUTER_KEY_VARIABLE] = $openRouterKey;
        putenv(self::OPENROUTER_KEY_VARIABLE . '=' . $openRouterKey);
    }

    /**
     * The cells of a row, in call order: `request <label> <mode>`, `breaker trip|close`, `slice <key>`,
     * `card <site> <decidedBy> applied|not applied`, `event <kind> <site>[ <subject>] decidedBy=<json>`,
     * `event payload update <site>`, `result …`. The site-service calls (`site …`) and the claim-fenced write are the
     * sequence's ({@see sequenceOfWorldLog()}).
     *
     * @return string[]
     */
    protected function summarizeWorldLog(): array
    {
        return array_values(array_filter($this->worldLog->lines, static fn (string $line): bool => !str_starts_with($line, 'site ') && !str_starts_with($line, 'claim-fenced ')));
    }

    /**
     * The ORDER of a turn-start row: every site-service call, request, card, turn event, breaker request and the
     * claim-fenced write, without the slices and the payload updates.
     *
     * @return string[]
     */
    protected function sequenceOfWorldLog(): array
    {
        return array_values(array_filter($this->worldLog->lines, static fn (string $line): bool => !str_starts_with($line, 'slice ') && !str_starts_with($line, 'event payload update') && !str_starts_with($line, 'result ')));
    }

    // --- the questions the site services build (the row's inputs) ---------------------------------------------------------

    public const string ROOT_SKILL = 'Fixture.Profile';

    public const string SUB_SKILL_ADDRESS = 'Fixture.ProfileAddress';

    public const string SUB_SKILL_LINKS = 'Fixture.ProfileLinks';

    public const string OTHER_ROOT_SKILL = 'Fixture.Keywords';

    /** The reply-language question of `AgentReplyLanguageDecisionsService::buildRequestInput()`: configured German, anchored on German. */
    protected function replyLanguageQuestion(string $standingDirectivesBlock = '', ?string $anchorLanguageCode = 'de'): AgentReplyLanguageDecisionQuestion
    {
        $replyLanguageDecision = new AgentReplyLanguageDecision();
        $replyLanguageDecision->ownerMessageIndex = 2;
        $replyLanguageDecision->decidedAt = '2026-10-22 10:00:00';
        $replyLanguageDecision->configuredLanguageCode = 'de';
        $replyLanguageDecision->anchorLanguageCode = $anchorLanguageCode;
        $replyLanguageDecision->anchorDecidedAt = $anchorLanguageCode !== null ? '2026-10-20 09:00:00' : null;
        $replyLanguageDecision->directiveValidFrom = null;
        $question = new AgentReplyLanguageDecisionQuestion();
        $question->ownerMessageIndex = 2;
        $question->ownerWords = self::OWNER_MESSAGE;
        $question->configuredLanguageCode = 'de';
        $question->supportedLanguageCodes = ['de', 'en'];
        $question->standingDirectivesBlock = $standingDirectivesBlock;
        $question->newestDirectiveValidFrom = null;
        $question->anchorLanguageCode = $anchorLanguageCode;
        $question->anchorInstant = $anchorLanguageCode !== null ? new DateTime('2026-10-20 09:00:00') : null;
        $question->replyLanguageDecision = $replyLanguageDecision;
        return $question;
    }

    /** The effort question of `AgentReasoningEffortDecisionsService::buildReasoningEffortRequestInput()`. */
    protected function reasoningEffortQuestion(bool $askDecisionModel = true, string $floorEffort = AgentDecisionModelSettings::REASONING_EFFORT_LOW, string $disagreement = ''): AgentReasoningEffortDecisionQuestion
    {
        $question = new AgentReasoningEffortDecisionQuestion();
        $question->mode = AgentDecisionModelSettings::MODE_ON;
        $question->initiatorMessageIndex = 2;
        $question->askDecisionModel = $askDecisionModel;
        $question->preconditionReason = $askDecisionModel ? 'wordFloor' : 'none';
        $question->floorEffort = $floorEffort;
        $question->floorSource = 'configured';
        $question->ceilingEffort = AgentDecisionModelSettings::REASONING_EFFORT_HIGH;
        $question->ownerMessage = self::OWNER_MESSAGE;
        $question->assistantTextBefore = self::ASSISTANT_TEXT_BEFORE;
        $question->storedFacts = 'name: Corner Shop';
        $question->disagreement = $disagreement;
        return $question;
    }

    /** The plan question of `AgentPlanDynamicsDecisionsService::buildRequestInput()`. */
    protected function planDynamicsQuestion(): AgentPlanDynamicsDecisionQuestion
    {
        $question = new AgentPlanDynamicsDecisionQuestion();
        $question->mode = AgentDecisionModelSettings::MODE_ON;
        $question->initiatorMessageIndex = 2;
        $question->ownerMessage = self::OWNER_MESSAGE;
        $question->assistantTextBefore = self::ASSISTANT_TEXT_BEFORE;
        $question->recentTurns = ['owner: Hello, this is the corner shop on the market square.'];
        $question->finishedPlans = ['#1 Profile cleanup — 4 done, 1 not applicable'];
        return $question;
    }

    /**
     * The turn-start batch service over the site doubles. `$sites` names the sites that contribute: `replyLanguage`,
     * `skillPreload`, `loadedSkillSubSkills` (one child under {@see ROOT_SKILL}), `reasoningEffort`,
     * `reasoningEffortPrecondition` (the effort question whose precondition decides without the model), `planDynamics`,
     * `ownerMessageInjection`.
     *
     * @param string[] $sites
     */
    protected function turnStartBatchService(array $sites, string $standingDirectivesBlock = '', string $effortFloor = AgentDecisionModelSettings::REASONING_EFFORT_LOW, string $effortDisagreement = ''): TurnStartDecisionBatchServiceOverSiteDoubles
    {
        $replyLanguageServiceDouble = new ReplyLanguageServiceWithScriptedInputDouble($this->worldLog);
        $replyLanguageServiceDouble->scriptedInput = in_array('replyLanguage', $sites, true) ? $this->replyLanguageQuestion($standingDirectivesBlock) : null;
        $skillPreloadServiceDouble = new SkillPreloadServiceWithScriptedInputsDouble($this->worldLog);
        if (in_array('skillPreload', $sites, true)) {
            $skillPreloadServiceDouble->scriptedInputs['roots'] = $this->skillPreloadRootsQuestion();
        }
        if (in_array('loadedSkillSubSkills', $sites, true)) {
            $skillPreloadServiceDouble->scriptedLoadedSkillSubSkillInputs[self::ROOT_SKILL] = $this->subSkillPassQuestion('loadedSkill');
        }
        $reasoningEffortServiceDouble = new ReasoningEffortServiceWithScriptedReadsDouble($this->worldLog);
        if (in_array('reasoningEffort', $sites, true)) {
            $reasoningEffortServiceDouble->scriptedInput = $this->reasoningEffortQuestion(true, $effortFloor, $effortDisagreement);
        } elseif (in_array('reasoningEffortPrecondition', $sites, true)) {
            $reasoningEffortServiceDouble->scriptedInput = $this->reasoningEffortQuestion(false);
        }
        $planDynamicsServiceDouble = new PlanDynamicsServiceWithScriptedInputDouble($this->worldLog);
        $planDynamicsServiceDouble->scriptedInput = in_array('planDynamics', $sites, true) ? $this->planDynamicsQuestion() : null;
        $ownerMessageScreeningServiceDouble = new OwnerMessageScreeningServiceRecordingDouble($this->worldLog);
        $ownerMessageScreeningServiceDouble->skipsTheCheck = !in_array('ownerMessageInjection', $sites, true);
        return new TurnStartDecisionBatchServiceOverSiteDoubles(
            $replyLanguageServiceDouble,
            $skillPreloadServiceDouble,
            $reasoningEffortServiceDouble,
            $planDynamicsServiceDouble,
            $ownerMessageScreeningServiceDouble,
            new PurposeRunsServiceWithScriptedAnswersDouble()
        );
    }

    /** The roots pass question of `AgentSkillPreloadDecisionsService::buildSkillPreloadRequestInput()`. */
    protected function skillPreloadRootsQuestion(): AgentSkillPreloadDecisionQuestion
    {
        $question = new AgentSkillPreloadDecisionQuestion();
        $question->mode = AgentDecisionModelSettings::MODE_ON;
        $question->overrideSource = 'none';
        $question->pass = 1;
        $question->initiatorMessageIndex = 2;
        $question->catalogTokens = [strtolower(self::OTHER_ROOT_SKILL) => self::OTHER_ROOT_SKILL, strtolower(self::ROOT_SKILL) => self::ROOT_SKILL];
        $question->catalogCriteria = [self::OTHER_ROOT_SKILL => 'The search terms of the business', self::ROOT_SKILL => 'The profile of the business: name, address, hours'];
        $question->loadedSkills = new AgentSkillPreloadLoadedSkills();
        $question->recentTurns = ['owner: Hello, this is the corner shop on the market square.'];
        $question->agentSpaceFacts = 'name: Corner Shop';
        $question->metaTools = ['load_skills' => 'Load one or more skills from the catalog'];
        $question->ownerMessage = self::OWNER_MESSAGE;
        $question->assistantTextBefore = self::ASSISTANT_TEXT_BEFORE;
        $question->criteriaVersion = 'test-criteria';
        $question->snapshot = ['offered' => [strtolower(self::ROOT_SKILL) => ['name' => self::ROOT_SKILL, 'toolNames' => ['readProfile']]], 'loaded' => [], 'criteriaVersion' => 'test-criteria'];
        return $question;
    }

    /**
     * A sub-skill pass question (`loadedSkill:` at the turn start, `pass<n>:` as the follow-up, `modelLoad:` after the
     * chat model's own load) under {@see ROOT_SKILL}.
     */
    protected function subSkillPassQuestion(string $subjectPrefix, int $pass = 2): AgentSkillPreloadDecisionQuestion
    {
        $question = new AgentSkillPreloadDecisionQuestion();
        $question->mode = AgentDecisionModelSettings::MODE_ON;
        $question->overrideSource = 'none';
        $question->pass = $pass;
        $question->unlockedByRootSkillName = self::ROOT_SKILL;
        $question->subject = $subjectPrefix . ':' . self::ROOT_SKILL;
        $question->subSkillPassSubject = str_starts_with($subjectPrefix, 'pass') ? null : $subjectPrefix;
        $question->unlockedByRootToolSummaries = ['readProfile' => 'Read the profile', 'writeProfile' => 'Write the profile'];
        $question->initiatorMessageIndex = 2;
        $question->catalogTokens = [strtolower(self::SUB_SKILL_ADDRESS) => self::SUB_SKILL_ADDRESS, strtolower(self::SUB_SKILL_LINKS) => self::SUB_SKILL_LINKS];
        $question->catalogCriteria = [self::SUB_SKILL_ADDRESS => 'The address and the service area', self::SUB_SKILL_LINKS => 'The website and the links'];
        $question->loadedSkills = new AgentSkillPreloadLoadedSkills();
        $question->recentTurns = ['owner: Hello, this is the corner shop on the market square.'];
        $question->agentSpaceFacts = 'name: Corner Shop';
        $question->rootSkillDescription = 'The profile skill: identity first, then the address';
        $question->ownerMessage = self::OWNER_MESSAGE;
        $question->assistantTextBefore = self::ASSISTANT_TEXT_BEFORE;
        $question->criteriaVersion = 'test-criteria';
        $question->snapshot = [
            'offered' => [strtolower(self::SUB_SKILL_ADDRESS) => ['name' => self::SUB_SKILL_ADDRESS, 'toolNames' => ['writeAddress']]],
            'loaded' => [self::ROOT_SKILL => ['toolNames' => ['readProfile', 'writeProfile']]],
            'criteriaVersion' => 'test-criteria',
        ];
        return $question;
    }

    // --- builders -------------------------------------------------------------------------------------------------------

    /** @param AIConversationMessage[] $messageList */
    protected function conversation(array $messageList, ?AgentWorkingState $agentWorkingState = null): AIConversation
    {
        $messages = new AIConversationMessages();
        foreach ($messageList as $message) {
            $messages->add($message);
        }
        /** @var AIConversation $conversation */
        $conversation = (new ReflectionClass(AIConversation::class))->newInstanceWithoutConstructor();
        $conversation->id = self::CONVERSATION_ID;
        $conversation->messages = $messages;
        if ($agentWorkingState !== null) {
            $conversation->agentWorkingState = $agentWorkingState;
        }
        return $conversation;
    }

    /** The three rows of the row's turn: owner @0, the assistant text @1, the owner message @2. */
    protected function ownerTurnConversation(?AgentWorkingState $agentWorkingState = null): AIConversation
    {
        return $this->conversation([
            $this->ownerText(0, 'Hello, this is the corner shop on the market square.'),
            $this->assistantText(1, self::ASSISTANT_TEXT_BEFORE),
            $this->ownerText(2, self::OWNER_MESSAGE),
        ], $agentWorkingState);
    }

    protected function textMessage(int $index, string $role, string $authorType, string $text): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->indexInConversation = $index;
        $message->role = $role;
        $message->authorType = $authorType;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = $text;
        return $message;
    }

    protected function ownerText(int $index, string $text): AIConversationMessage
    {
        return $this->textMessage($index, AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, $text);
    }

    protected function assistantText(int $index, string $text): AIConversationMessage
    {
        return $this->textMessage($index, AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, $text);
    }

    /**
     * Calls a protected method of a service the way its caller inside the package does.
     *
     * @param array<int, mixed> $arguments
     */
    protected static function callProtectedMethod(object $object, string $method, array $arguments): mixed
    {
        return (new ReflectionMethod($object, $method))->invokeArgs($object, $arguments);
    }
}
