<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Evals\AgentEvalArms;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * The ARM options every evaluation command shares: one switch per decision site, the kill switch, the fixed reasoning
 * effort, the agent wire arms and the request-snapshot flag. An option that is NOT passed (or says `default` /
 * `inherit`) inherits the platform default, so a run without arm options measures what real conversations get.
 *
 * {@see resolveEvalArmOptions()} validates the passed arms into option name → canonical value (the form a child
 * process of the same run gets back through {@see buildEvalArmArguments()}); {@see buildEvalArms()} turns them into
 * the {@see AgentEvalArms} every conversation of the run is built with. The decision sites need a stored conversation:
 * a run that stores nothing never reaches them, whatever the arms say.
 *
 * The arms `skill-loading-strategy`, `rebuild-epoch-on-situation-change` and `state-change-delivery` have the same
 * names as three options of {@see ContextRetentionSettingsOptionsTrait}: a command uses one of the two traits for
 * them, never both.
 */
trait AgentEvalArmOptionsTrait
{
    public const string ARM_DECISION_MODEL = 'decision-model';
    public const string ARM_REPLY_LANGUAGE = 'decision-model-reply-language';
    public const string ARM_SKILL_PRELOAD = 'decision-model-skill-preload';
    public const string ARM_TURN_CLOSING = 'decision-model-turn-closing';
    public const string ARM_PLAN_DYNAMICS = 'decision-model-plan-dynamics';
    public const string ARM_REASONING_EFFORT_ROW = 'decision-model-reasoning-effort';
    public const string ARM_STALL_RECOVERY = 'decision-model-stall-recovery';
    public const string ARM_FIXED_REASONING_EFFORT = 'reasoning-effort';
    public const string ARM_AGENT_WIRE_API = 'agent-wire-api';
    public const string ARM_PREVIOUS_REASONING_CARRY_OVER = 'previous-reasoning-carry-over';
    /** A COUNT arm (no choice): validated by {@see resolveEvalArmOptions()} itself, outside {@see getEvalArmChoices()}. */
    public const string ARM_PREVIOUS_REASONING_CAP_TOKENS = 'previous-reasoning-cap-tokens';
    /** A COUNT arm (0 or more), validated like {@see ARM_PREVIOUS_REASONING_CAP_TOKENS}. */
    public const string ARM_PREVIOUS_REASONING_KEPT_TOKENS_AT_CUT = 'previous-reasoning-kept-tokens-at-cut';
    /** A COUNT arm (a positive count), validated like {@see ARM_PREVIOUS_REASONING_CAP_TOKENS}. */
    public const string ARM_PREVIOUS_REASONING_KEPT_STEPS_AT_CUT = 'previous-reasoning-kept-steps-at-cut';
    /** A COUNT arm (0 or more), validated like {@see ARM_PREVIOUS_REASONING_CAP_TOKENS}. */
    public const string ARM_PREVIOUS_REASONING_HEADROOM_TOKENS_AFTER_CUT = 'previous-reasoning-headroom-tokens-after-cut';

    /** @var array<string, int> The count arms of the carry-over cut and the smallest value each accepts */
    public const array PREVIOUS_REASONING_CUT_COUNT_ARMS_MINIMUM_VALUES = [
        self::ARM_PREVIOUS_REASONING_KEPT_TOKENS_AT_CUT => 0,
        self::ARM_PREVIOUS_REASONING_KEPT_STEPS_AT_CUT => 1,
        self::ARM_PREVIOUS_REASONING_HEADROOM_TOKENS_AFTER_CUT => 0,
    ];
    public const string ARM_SKILL_LOADING_STRATEGY = 'skill-loading-strategy';
    public const string ARM_REBUILD_EPOCH_ON_SITUATION_CHANGE = 'rebuild-epoch-on-situation-change';
    public const string ARM_STATE_CHANGE_DELIVERY = 'state-change-delivery';
    public const string ARM_TOOL_DELIVERY = 'tool-delivery';
    public const string ARM_DROP_COLD_SKILLS_AT_REBASE = 'drop-cold-skills-at-rebase';
    /** A FLAG arm (no value): resolved by {@see resolveEvalArmOptions()} itself, passed to a child as the bare flag. */
    public const string ARM_STORE_SNAPSHOT = 'store-snapshot';

    /** @var string The value {@see ARM_STORE_SNAPSHOT} resolves to when the flag was passed */
    public const string ARM_FLAG_VALUE_ON = 'on';

    /** @var string[] The two values of {@see ARM_REBUILD_EPOCH_ON_SITUATION_CHANGE} */
    public const array REBUILD_EPOCH_ON_SITUATION_CHANGE_ARM_VALUES = ['on', 'off'];

    /** @var string The value every arm option accepts for "the platform default", the same as not passing the option */
    public const string ARM_VALUE_DEFAULT = 'default';

    /** @var string The second spelling of "the platform default" */
    public const string ARM_VALUE_INHERIT = 'inherit';

    /**
     * Every arm option → the values it accepts beside `default` / `inherit`.
     *
     * @return array<string, string[]>
     */
    public static function getEvalArmChoices(): array
    {
        return [
            self::ARM_DECISION_MODEL => ['off'],
            self::ARM_REPLY_LANGUAGE => [RunSettings::DECISION_MODEL_REPLY_LANGUAGE_ON, RunSettings::DECISION_MODEL_REPLY_LANGUAGE_OFF],
            self::ARM_SKILL_PRELOAD => [RunSettings::DECISION_MODEL_SKILL_PRELOAD_ON, RunSettings::DECISION_MODEL_SKILL_PRELOAD_OFF],
            self::ARM_TURN_CLOSING => array_merge([RunSettings::DECISION_MODEL_TURN_CLOSING_OFF], AgentDecisionModelSettings::TURN_CLOSING_MODES),
            self::ARM_PLAN_DYNAMICS => AgentEvalArms::PLAN_DYNAMICS_ARMS,
            self::ARM_REASONING_EFFORT_ROW => array_merge([RunSettings::DECISION_MODEL_REASONING_EFFORT_OFF], AgentDecisionModelSettings::REASONING_EFFORTS),
            self::ARM_STALL_RECOVERY => [RunSettings::DECISION_MODEL_STALL_RECOVERY_ON, RunSettings::DECISION_MODEL_STALL_RECOVERY_OFF],
            self::ARM_FIXED_REASONING_EFFORT => AgentDecisionModelSettings::REASONING_EFFORTS,
            self::ARM_AGENT_WIRE_API => RunSettings::AGENT_WIRE_APIS,
            self::ARM_PREVIOUS_REASONING_CARRY_OVER => RunSettings::PREVIOUS_REASONING_CARRY_OVER_VALUES,
            self::ARM_SKILL_LOADING_STRATEGY => ContextRetentionSettings::SKILL_LOADING_STRATEGIES,
            self::ARM_REBUILD_EPOCH_ON_SITUATION_CHANGE => self::REBUILD_EPOCH_ON_SITUATION_CHANGE_ARM_VALUES,
            self::ARM_STATE_CHANGE_DELIVERY => ContextRetentionSettings::STATE_CHANGE_DELIVERIES,
            self::ARM_TOOL_DELIVERY => ContextRetentionSettings::TOOL_DELIVERIES,
            self::ARM_DROP_COLD_SKILLS_AT_REBASE => self::REBUILD_EPOCH_ON_SITUATION_CHANGE_ARM_VALUES,
        ];
    }

    /**
     * Declares the arm options. Call it from `configure()`.
     *
     * @return void
     */
    protected function addEvalArmOptions(): void
    {
        $this
            ->addOption(self::ARM_DECISION_MODEL, null, InputOption::VALUE_REQUIRED, 'The decision layer as a whole: `off` = the kill switch, no decision site runs in the run\'s conversations. Not passed = every site as configured.')
            ->addOption(self::ARM_REPLY_LANGUAGE, null, InputOption::VALUE_REQUIRED, 'The reply-language site: `on` | `off`. Not passed = the platform default.')
            ->addOption(self::ARM_SKILL_PRELOAD, null, InputOption::VALUE_REQUIRED, 'The skill-preload site: `on` = the decision model preloads the skill before the first model call, `off` = the chat model loads on its own. Not passed = the platform default.')
            ->addOption(self::ARM_TURN_CLOSING, null, InputOption::VALUE_REQUIRED, 'The checked stop after a turn: `off` | `' . implode('` | `', AgentDecisionModelSettings::TURN_CLOSING_MODES) . '`. Not passed = the platform default.')
            ->addOption(self::ARM_PLAN_DYNAMICS, null, InputOption::VALUE_REQUIRED, 'The task-plan site: `off` = the chat model decides alone, `observe` = the decision model is asked and recorded only, `guide` = the chat model receives the verdict. Not passed = the platform default.')
            ->addOption(self::ARM_REASONING_EFFORT_ROW, null, InputOption::VALUE_REQUIRED, 'The reasoning-effort site: `off` = the configured effort without a decision, `low` | `medium` | `high` = a floor the site may raise. Not passed = the site decides. For an effort sent unchanged use --' . self::ARM_FIXED_REASONING_EFFORT . '.')
            ->addOption(self::ARM_AGENT_WIRE_API, null, InputOption::VALUE_REQUIRED, 'The wire API of the agent calls: `' . implode('` | `', RunSettings::AGENT_WIRE_APIS) . '`. Inherited by delegated conversations. Not passed = the model catalog.')
            ->addOption(self::ARM_PREVIOUS_REASONING_CARRY_OVER, null, InputOption::VALUE_REQUIRED, 'Which reasoning items of the previous calls go back on the later calls: `all` | `currentTurn` | `capped` (until --' . self::ARM_PREVIOUS_REASONING_CAP_TOKENS . ' is exceeded) | `off`. Not passed = the default of the wire API.')
            ->addOption(self::ARM_PREVIOUS_REASONING_CAP_TOKENS, null, InputOption::VALUE_REQUIRED, 'The cap of --' . self::ARM_PREVIOUS_REASONING_CARRY_OVER . '=capped in reasoning tokens (a positive count). Not passed = the committed default.')
            ->addOption(self::ARM_PREVIOUS_REASONING_KEPT_TOKENS_AT_CUT, null, InputOption::VALUE_REQUIRED, 'At a cut of --' . self::ARM_PREVIOUS_REASONING_CARRY_OVER . '=capped: keep whole steps from the newest back until at least this many reasoning tokens (0 or more). Not passed = the model\'s or the committed default.')
            ->addOption(self::ARM_PREVIOUS_REASONING_KEPT_STEPS_AT_CUT, null, InputOption::VALUE_REQUIRED, 'At a cut of --' . self::ARM_PREVIOUS_REASONING_CARRY_OVER . '=capped: keep at least this many of the newest steps (a positive count). Not passed = the model\'s or the committed default.')
            ->addOption(self::ARM_PREVIOUS_REASONING_HEADROOM_TOKENS_AFTER_CUT, null, InputOption::VALUE_REQUIRED, 'After a cut of --' . self::ARM_PREVIOUS_REASONING_CARRY_OVER . '=capped: the next cut waits until the carried reasoning exceeds the kept reasoning plus this many tokens (0 or more). Not passed = the model\'s or the committed default.')
            ->addOption(self::ARM_SKILL_LOADING_STRATEGY, null, InputOption::VALUE_REQUIRED, 'How a loaded skill reaches the model: `' . implode('` | `', ContextRetentionSettings::SKILL_LOADING_STRATEGIES) . '`. Stamped at each conversation\'s creation. Not passed = the purpose\'s or the global default.')
            ->addOption(self::ARM_REBUILD_EPOCH_ON_SITUATION_CHANGE, null, InputOption::VALUE_REQUIRED, 'With append-only skill loading: `on` = a situation change starts a new prefix epoch, `off` = the change is appended. Stamped at each conversation\'s creation. Not passed = the purpose\'s or the global default.')
            ->addOption(self::ARM_STATE_CHANGE_DELIVERY, null, InputOption::VALUE_REQUIRED, 'How a change of a volatile value reaches the model: `' . implode('` | `', ContextRetentionSettings::STATE_CHANGE_DELIVERIES) . '`. Stamped at each conversation\'s creation. Not passed = the purpose\'s or the global default.')
            ->addOption(self::ARM_TOOL_DELIVERY, null, InputOption::VALUE_REQUIRED, 'With append-only skill loading, how a loaded skill\'s tools reach the model: `' . implode('` | `', ContextRetentionSettings::TOOL_DELIVERIES) . '`. Set at each conversation\'s creation. Not passed = the global default.')
            ->addOption(self::ARM_DROP_COLD_SKILLS_AT_REBASE, null, InputOption::VALUE_REQUIRED, 'With append-only skill loading: `on` = at the first model call after a compaction the skills unused for the configured owner turns are unloaded, `off` = every loaded skill is carried. Not passed = the global default.')
            ->addOption(self::ARM_STALL_RECOVERY, null, InputOption::VALUE_REQUIRED, 'The stall-recovery site: `on` | `off`. Not passed = the platform default.')
            ->addOption(self::ARM_STORE_SNAPSHOT, null, InputOption::VALUE_NONE, 'Store the full sent payload and the raw response of every model call of the run\'s conversations on their request snapshots.')
            ->addOption(self::ARM_FIXED_REASONING_EFFORT, null, InputOption::VALUE_REQUIRED, 'A fixed reasoning effort for every model call of the run: `low` | `medium` | `high` (the effort site is not asked, delegated conversations inherit it). Not passed = the platform resolves the effort.');
    }

    /**
     * The arms that were passed, validated: option name → canonical value. `default` and `inherit` count as not passed.
     *
     * @param InputInterface $input
     * @return array<string, string>
     * @throws InvalidOptionException on a value the option does not accept
     */
    protected function resolveEvalArmOptions(InputInterface $input): array
    {
        $arms = [];
        foreach (self::getEvalArmChoices() as $optionName => $choices) {
            $rawValue = $input->hasOption($optionName) ? $input->getOption($optionName) : null;
            if (!is_string($rawValue) || trim($rawValue) === '') {
                continue;
            }
            $normalizedValue = strtolower(trim($rawValue));
            if ($normalizedValue === self::ARM_VALUE_DEFAULT || $normalizedValue === self::ARM_VALUE_INHERIT) {
                continue;
            }
            $canonicalValue = null;
            foreach ($choices as $choice) {
                if ($normalizedValue === strtolower($choice)) {
                    $canonicalValue = $choice;
                    break;
                }
            }
            if ($canonicalValue === null) {
                throw new InvalidOptionException('--' . $optionName . ' must be one of: ' . implode(', ', $choices) . ' (or ' . self::ARM_VALUE_DEFAULT . ')');
            }
            $arms[$optionName] = $canonicalValue;
        }
        $capTokensValue = $input->hasOption(self::ARM_PREVIOUS_REASONING_CAP_TOKENS) ? $input->getOption(self::ARM_PREVIOUS_REASONING_CAP_TOKENS) : null;
        if (is_string($capTokensValue) && trim($capTokensValue) !== '' && !in_array(strtolower(trim($capTokensValue)), [self::ARM_VALUE_DEFAULT, self::ARM_VALUE_INHERIT], true)) {
            if (!ctype_digit(trim($capTokensValue)) || (int)trim($capTokensValue) <= 0) {
                throw new InvalidOptionException('--' . self::ARM_PREVIOUS_REASONING_CAP_TOKENS . ' must be a positive number of reasoning tokens (or ' . self::ARM_VALUE_DEFAULT . ' / ' . self::ARM_VALUE_INHERIT . ')');
            }
            $arms[self::ARM_PREVIOUS_REASONING_CAP_TOKENS] = (string)(int)trim($capTokensValue);
        }
        foreach (self::PREVIOUS_REASONING_CUT_COUNT_ARMS_MINIMUM_VALUES as $countOptionName => $minimumValue) {
            $countValue = $input->hasOption($countOptionName) ? $input->getOption($countOptionName) : null;
            if (!is_string($countValue) || trim($countValue) === '' || in_array(strtolower(trim($countValue)), [self::ARM_VALUE_DEFAULT, self::ARM_VALUE_INHERIT], true)) {
                continue;
            }
            if (!ctype_digit(trim($countValue)) || (int)trim($countValue) < $minimumValue) {
                throw new InvalidOptionException('--' . $countOptionName . ' must be a whole number of at least ' . $minimumValue . ' (or ' . self::ARM_VALUE_DEFAULT . ' / ' . self::ARM_VALUE_INHERIT . ')');
            }
            $arms[$countOptionName] = (string)(int)trim($countValue);
        }
        if ($input->hasOption(self::ARM_STORE_SNAPSHOT) && (bool)$input->getOption(self::ARM_STORE_SNAPSHOT)) {
            $arms[self::ARM_STORE_SNAPSHOT] = self::ARM_FLAG_VALUE_ON;
        }
        return $arms;
    }

    /**
     * The arms of the run as the value object every conversation of the run is built with. An empty arm set gives
     * the arms of the platform default.
     *
     * @param array<string, string> $arms {@see resolveEvalArmOptions()}
     * @return AgentEvalArms
     */
    protected function buildEvalArms(array $arms): AgentEvalArms
    {
        $agentEvalArms = AgentEvalArms::newInstance();
        $agentEvalArms->decisionModelOff = ($arms[self::ARM_DECISION_MODEL] ?? null) === 'off';
        $agentEvalArms->replyLanguageRow = $arms[self::ARM_REPLY_LANGUAGE] ?? null;
        $agentEvalArms->skillPreloadRow = $arms[self::ARM_SKILL_PRELOAD] ?? null;
        $agentEvalArms->turnClosingRow = $arms[self::ARM_TURN_CLOSING] ?? null;
        $agentEvalArms->planDynamicsArm = $arms[self::ARM_PLAN_DYNAMICS] ?? null;
        $agentEvalArms->reasoningEffortRow = $arms[self::ARM_REASONING_EFFORT_ROW] ?? null;
        $agentEvalArms->stallRecoveryRow = $arms[self::ARM_STALL_RECOVERY] ?? null;
        $agentEvalArms->fixedReasoningEffort = $arms[self::ARM_FIXED_REASONING_EFFORT] ?? null;
        $agentEvalArms->agentWireApi = $arms[self::ARM_AGENT_WIRE_API] ?? null;
        $agentEvalArms->previousReasoningCarryOver = $arms[self::ARM_PREVIOUS_REASONING_CARRY_OVER] ?? null;
        $agentEvalArms->previousReasoningCapTokens = isset($arms[self::ARM_PREVIOUS_REASONING_CAP_TOKENS]) ? (int)$arms[self::ARM_PREVIOUS_REASONING_CAP_TOKENS] : null;
        $agentEvalArms->previousReasoningKeptTokensAtCut = isset($arms[self::ARM_PREVIOUS_REASONING_KEPT_TOKENS_AT_CUT]) ? (int)$arms[self::ARM_PREVIOUS_REASONING_KEPT_TOKENS_AT_CUT] : null;
        $agentEvalArms->previousReasoningKeptStepsAtCut = isset($arms[self::ARM_PREVIOUS_REASONING_KEPT_STEPS_AT_CUT]) ? (int)$arms[self::ARM_PREVIOUS_REASONING_KEPT_STEPS_AT_CUT] : null;
        $agentEvalArms->previousReasoningHeadroomTokensAfterCut = isset($arms[self::ARM_PREVIOUS_REASONING_HEADROOM_TOKENS_AFTER_CUT]) ? (int)$arms[self::ARM_PREVIOUS_REASONING_HEADROOM_TOKENS_AFTER_CUT] : null;
        $agentEvalArms->skillLoadingStrategy = $arms[self::ARM_SKILL_LOADING_STRATEGY] ?? null;
        $agentEvalArms->stateChangeDelivery = $arms[self::ARM_STATE_CHANGE_DELIVERY] ?? null;
        $agentEvalArms->toolDelivery = $arms[self::ARM_TOOL_DELIVERY] ?? null;
        $agentEvalArms->dropColdSkillsAtCompactionRebase = isset($arms[self::ARM_DROP_COLD_SKILLS_AT_REBASE])
            ? $arms[self::ARM_DROP_COLD_SKILLS_AT_REBASE] === 'on'
            : null;
        $agentEvalArms->rebuildEpochOnSituationChange = isset($arms[self::ARM_REBUILD_EPOCH_ON_SITUATION_CHANGE])
            ? $arms[self::ARM_REBUILD_EPOCH_ON_SITUATION_CHANGE] === 'on'
            : null;
        $agentEvalArms->storeRequestSnapshot = isset($arms[self::ARM_STORE_SNAPSHOT]);
        return $agentEvalArms;
    }

    /**
     * The arms as console arguments, for a child process of the same run.
     *
     * @param array<string, string> $arms {@see resolveEvalArmOptions()}
     * @return string[]
     */
    public static function buildEvalArmArguments(array $arms): array
    {
        $arguments = [];
        foreach ($arms as $optionName => $armValue) {
            // the flag arm takes no value
            $arguments[] = $optionName === self::ARM_STORE_SNAPSHOT ? '--' . $optionName : '--' . $optionName . '=' . $armValue;
        }
        return $arguments;
    }
}
