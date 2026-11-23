<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * One case of an evaluation fixture, built from the decoded JSON object of the case.
 *
 * The generic keys of the fixture format have typed accessors here: `id`, `prompt`, `followUpPrompts`,
 * `followUpIdleMinutes`, `turns`, `maxTurns`, `maxStepsPerTurn`, `timeoutSeconds`, `purposeName`, `reportChannel`,
 * `scheduledRun`, `agentSpaceId`, `blockedTools`, `blockedToolSimulatedErrors`, `allowExternalWrites`, `sequential`,
 * `executionMode`, `requiresVision`, `requires`, `runsOnIneligibleScope`, `runsWithoutCapability`, the `expect` block
 * (with its state check `expect.stateAfterRun`), the `restore` block, and the keys a suite plans a case by: `access`,
 * `exclusive`, `lane` and the `expectedSkill` of a selection case. Every other key is an application's own: it is
 * kept unchanged and read with {@see getApplicationValue()}; {@see toArray()} gives back the case exactly as it was
 * built.
 *
 * The tool block of a case is resolved here: the case's own `blockedTools`, then every tool the application counts as
 * a write to a real external profile ({@see \DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService::getExternalWriteToolNames()})
 * that the case does not name in `allowExternalWrites`. A case whose purpose is such a write, and that restores it,
 * opts in there; its own `blockedTools` always win over the opt-in. The harness's own preparation, restore and cleanup
 * calls are calls outside the agent's run and are not blocked.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalCase extends ValueObject
{
    /** @var string The key that lists the external-write tools the case may call */
    public const string KEY_ALLOW_EXTERNAL_WRITES = 'allowExternalWrites';

    /** @var string The key that names the stored agent space the case runs in */
    public const string KEY_AGENT_SPACE_ID = 'agentSpaceId';

    /**
     * @var string The record kind of an agent space named by reference: `"agentSpaceId": "{{ref:agentSpace:<name>}}"`,
     *      resolved by the application without a scope ({@see \DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService::resolveAgentSpaceReference()})
     */
    public const string AGENT_SPACE_REFERENCE_KIND = 'agentSpace';

    /** @var string The key that declares how a case touches its agent space in a suite (`read`, `write`, `exclusive`) */
    public const string KEY_ACCESS = 'access';

    /** @var string The key that makes a writing case run alone on its agent space in a suite */
    public const string KEY_EXCLUSIVE = 'exclusive';

    /** @var string The key that names the resource group of a case in a suite */
    public const string KEY_LANE = 'lane';

    /** @var string The files the case puts into its own workspace before the agent runs ({@see AgentEvalScratchFiles}) */
    public const string KEY_SCRATCH_FILES = 'scratchFiles';

    /** @var string The key that names the skill a selection case expects */
    public const string KEY_EXPECTED_SKILL = 'expectedSkill';

    /** @var string The `expectedSkill` of a selection case that expects the agent to load no skill at all */
    public const string EXPECTED_SKILL_NONE = 'none';

    /** @var string The key that names the skills a selection case must not see loaded */
    public const string KEY_FORBIDDEN_SKILLS = 'forbiddenSkills';

    /** @var string The execution mode of a case whose flow exists only on the job queue */
    public const string EXECUTION_MODE_ASYNC = 'async';

    /** @var string The execution mode of every other case (the default) */
    public const string EXECUTION_MODE_SYNC = 'sync';

    /** @var array<string, mixed> The case as it was decoded */
    protected array $caseFields = [];

    /**
     * The case of the decoded fixture object `$caseFields`.
     *
     * @param array<string, mixed> $caseFields
     * @return static
     */
    public static function fromArray(array $caseFields): static
    {
        $agentEvalCase = static::newInstance();
        $agentEvalCase->caseFields = $caseFields;
        return $agentEvalCase;
    }

    /**
     * The case exactly as it was built, unknown keys included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->caseFields;
    }

    /** Whether the case carries the key `$key` (a null value counts as carried). */
    public function hasKey(string $key): bool
    {
        return array_key_exists($key, $this->caseFields);
    }

    /**
     * The value of a key of the case as it was decoded — for an application's own key (the module reads its generic
     * keys through the accessors below). Null when the case does not carry the key.
     */
    public function getApplicationValue(string $key): mixed
    {
        return $this->caseFields[$key] ?? null;
    }

    /** `id`: the id of the case; empty when the case names none. */
    public function getId(): string
    {
        return (string)($this->caseFields['id'] ?? '');
    }

    /** `prompt`: the first owner message; empty when the case names none. */
    public function getPrompt(): string
    {
        return (string)($this->caseFields['prompt'] ?? '');
    }

    /**
     * `followUpPrompts`: the owner messages sent one per turn after the first turn settled, in order, as written.
     *
     * @return string[]
     */
    public function getFollowUpPrompts(): array
    {
        return array_values(array_map('strval', (array)($this->caseFields['followUpPrompts'] ?? [])));
    }

    /**
     * `followUpIdleMinutes`: how long the owner was away before each follow-up, aligned with
     * {@see getFollowUpPrompts()}; a missing entry means no idle time.
     *
     * @return int[]
     */
    public function getFollowUpIdleMinutes(): array
    {
        return array_values(array_map('intval', (array)($this->caseFields['followUpIdleMinutes'] ?? [])));
    }

    /** Whether the case is a scripted multi-turn flow (`turns` is a list). */
    public function hasTurns(): bool
    {
        return is_array($this->caseFields['turns'] ?? null);
    }

    /**
     * `turns`: the owner messages of a scripted multi-turn flow, in order; empty when the case has none.
     *
     * @return string[]
     */
    public function getTurns(): array
    {
        return $this->hasTurns() ? array_values(array_map('strval', $this->caseFields['turns'])) : [];
    }

    /** `maxTurns`: the most owner turns a judged run drives; null = the runner's default. */
    public function getMaxTurns(): ?int
    {
        return isset($this->caseFields['maxTurns']) ? (int)$this->caseFields['maxTurns'] : null;
    }

    /** `maxStepsPerTurn`: the step cap of one agent turn; null = the purpose's or the platform's cap. */
    public function getMaxStepsPerTurn(): ?int
    {
        return isset($this->caseFields['maxStepsPerTurn']) ? (int)$this->caseFields['maxStepsPerTurn'] : null;
    }

    /** `timeoutSeconds`: how long the case may run as a child process; null = the caller's default. */
    public function getTimeoutSeconds(): ?int
    {
        return isset($this->caseFields['timeoutSeconds']) ? (int)$this->caseFields['timeoutSeconds'] : null;
    }

    /** `purposeName`: the purpose the case's conversation runs in; null for an open conversation. */
    public function getPurposeName(): ?string
    {
        $purposeName = $this->caseFields['purposeName'] ?? null;
        return is_string($purposeName) && trim($purposeName) !== '' ? $purposeName : null;
    }

    /**
     * `reportChannel`: the channel an unattended run reports on, read as text whenever the key is set (an empty text
     * included); null for a run the owner attends.
     */
    public function getReportChannel(): ?string
    {
        return isset($this->caseFields['reportChannel']) ? (string)$this->caseFields['reportChannel'] : null;
    }

    /** `scheduledRun`: true = the case replays one firing of a task schedule; any truthy value counts. */
    public function isScheduledRun(): bool
    {
        return (bool)($this->caseFields['scheduledRun'] ?? false);
    }

    /**
     * `agentSpaceId` as a number: the stored agent space the case runs in; null when the case names none or names it by
     * reference ({@see getAgentSpaceReferenceName()}; the run hooks resolve it, never this accessor).
     */
    public function getAgentSpaceId(): ?int
    {
        $agentSpaceIdValue = $this->caseFields[static::KEY_AGENT_SPACE_ID] ?? null;
        if (!is_int($agentSpaceIdValue) && !(is_string($agentSpaceIdValue) && ctype_digit($agentSpaceIdValue))) {
            return null;
        }
        $agentSpaceId = (int)$agentSpaceIdValue;
        return $agentSpaceId > 0 ? $agentSpaceId : null;
    }

    /**
     * The name of the agent space the case names by reference — `"agentSpaceId": "{{ref:agentSpace:<name>}}"` (the
     * token is the whole value; the name is trimmed) — so a fixture survives the space being created again; null when
     * `agentSpaceId` is a number, absent or no such token.
     */
    public function getAgentSpaceReferenceName(): ?string
    {
        $agentSpaceIdValue = $this->caseFields[static::KEY_AGENT_SPACE_ID] ?? null;
        if (!is_string($agentSpaceIdValue)
            || preg_match('/^\s*\{\{ref:' . preg_quote(static::AGENT_SPACE_REFERENCE_KIND, '/') . ':([^}]+)\}\}\s*$/', $agentSpaceIdValue, $referenceMatch) !== 1
        ) {
            return null;
        }
        $agentSpaceName = trim($referenceMatch[1]);
        return $agentSpaceName !== '' ? $agentSpaceName : null;
    }

    /**
     * `blockedTools`: the tools the case itself blocks for the agent's run, as written.
     *
     * @return string[]
     */
    public function getBlockedToolNames(): array
    {
        return array_values(array_map('strval', (array)($this->caseFields['blockedTools'] ?? [])));
    }

    /**
     * `blockedToolSimulatedErrors`: the error text a blocked tool answers, by tool name.
     *
     * @return array<string, string>
     */
    public function getBlockedToolSimulatedErrors(): array
    {
        return array_map('strval', (array)($this->caseFields['blockedToolSimulatedErrors'] ?? []));
    }

    /** `sequential`: true = the case never runs beside another case. */
    public function isSequential(): bool
    {
        return ($this->caseFields['sequential'] ?? false) === true;
    }

    /** `executionMode`: {@see EXECUTION_MODE_SYNC} (the default) or {@see EXECUTION_MODE_ASYNC}, lower case. */
    public function getExecutionMode(): string
    {
        return strtolower(trim((string)($this->caseFields['executionMode'] ?? static::EXECUTION_MODE_SYNC)));
    }

    /** Whether the flow the case tests exists only on the job queue. */
    public function isAsyncOnly(): bool
    {
        return $this->getExecutionMode() === static::EXECUTION_MODE_ASYNC;
    }

    /** `requiresVision`: true = the case runs only on a model that can see images. */
    public function requiresVision(): bool
    {
        return ($this->caseFields['requiresVision'] ?? false) === true;
    }

    /** `runsOnIneligibleScope`: true = the case tests the refusal on a locked agent space and stays applicable there. */
    public function runsOnIneligibleScope(): bool
    {
        return ($this->caseFields['runsOnIneligibleScope'] ?? false) === true;
    }

    /** `runsWithoutCapability`: true = the case tests the answer to a withheld skill or tool and runs where it is withheld. */
    public function runsWithoutCapability(): bool
    {
        return ($this->caseFields['runsWithoutCapability'] ?? false) === true;
    }

    /**
     * `requires`: the names of what the case needs from its agent space beyond skills and tools, as written. The
     * names are the application's; the requirements an application derives from the expected tools are its own
     * ({@see \DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService::findUnmetRequirementDetails()}).
     *
     * @return string[]
     */
    public function getRequirementNames(): array
    {
        return is_array($this->caseFields['requires'] ?? null) ? array_values(array_map('strval', $this->caseFields['requires'])) : [];
    }

    /**
     * `expect`: the expectation block as it was decoded; empty when the case has none.
     *
     * @return array<string, mixed>
     */
    public function getExpect(): array
    {
        return is_array($this->caseFields['expect'] ?? null) ? $this->caseFields['expect'] : [];
    }

    /**
     * `expect.stateAfterRun`: the state check after the run as it was decoded; null when the case has none.
     *
     * @return array<string, mixed>|null
     */
    public function getStateAfterRun(): ?array
    {
        $stateAfterRun = $this->getExpect()['stateAfterRun'] ?? null;
        return is_array($stateAfterRun) ? $stateAfterRun : null;
    }

    /**
     * `restore`: the restore block as it was decoded; null when the case has none.
     *
     * @return array<string, mixed>|null
     */
    public function getRestore(): ?array
    {
        return is_array($this->caseFields['restore'] ?? null) ? $this->caseFields['restore'] : null;
    }

    /**
     * Whether the harness calls tools itself for this case (a `restore` block or a state check): such a call names a
     * stored agent space, so the case's agent space is stored even when the run stores no conversation.
     */
    public function hasHarnessToolCalls(): bool
    {
        return $this->getRestore() !== null || $this->getStateAfterRun() !== null;
    }

    /**
     * The skills the case expects: `expect.skills` and `expect.skill`, duplicates dropped — read by the case's
     * expectation ({@see AgentEvalExpectation::getExpectedSkillNames()}), so the applicability check and the skills
     * check of the expectation read the same skills.
     *
     * @return string[]
     */
    public function getExpectedSkillNames(): array
    {
        return AgentEvalExpectation::fromExpectBlock($this->getExpect())->getExpectedSkillNames();
    }

    /**
     * `expect.anyOfSkills`: the skills of which one is expected.
     *
     * @return string[]
     */
    public function getExpectedAnyOfSkillNames(): array
    {
        $expect = $this->getExpect();
        return is_array($expect['anyOfSkills'] ?? null) ? array_values(array_map('strval', $expect['anyOfSkills'])) : [];
    }

    /**
     * `expect.tool`: the tools of which one is expected (one name or a list).
     *
     * @return string[]
     */
    public function getExpectedAnyOfToolNames(): array
    {
        $expect = $this->getExpect();
        if (!isset($expect['tool'])) {
            return [];
        }
        return is_array($expect['tool']) ? array_values(array_map('strval', $expect['tool'])) : [(string)$expect['tool']];
    }

    /**
     * `expect.requiredTools`: the tools that are all expected (one name or a list).
     *
     * @return string[]
     */
    public function getExpectedRequiredToolNames(): array
    {
        $expect = $this->getExpect();
        if (!isset($expect['requiredTools'])) {
            return [];
        }
        return is_array($expect['requiredTools']) ? array_values(array_map('strval', $expect['requiredTools'])) : [(string)$expect['requiredTools']];
    }

    /**
     * The label of the expected tool a case row carries under `tool`: the name of `expect.tool`, the names of an
     * any-of list joined by `|`, `?` when the case expects no tool.
     */
    public function getExpectedToolLabel(): string
    {
        $expectedTool = $this->getExpect()['tool'] ?? '?';
        if (is_array($expectedTool)) {
            return implode('|', array_map('strval', $expectedTool));
        }
        return (string)$expectedTool;
    }

    /**
     * The skill a decision model is expected to preload per owner turn: `expect.preloadSkills` (one entry per owner
     * turn, empty = no expectation, `none` = the turn is to preload nothing), else `expect.skill` for the first turn
     * only.
     *
     * @return string[]
     */
    public function getExpectedPreloadSkillNamesByTurn(): array
    {
        $expect = $this->getExpect();
        if (isset($expect['preloadSkills']) && is_array($expect['preloadSkills'])) {
            return array_values(array_map('strval', $expect['preloadSkills']));
        }
        return isset($expect['skill']) ? [(string)$expect['skill']] : [];
    }

    /** `expectedSkill`: the skill a selection case expects the agent to pick; null when the case names none. */
    public function getExpectedSkillName(): ?string
    {
        $expectedSkillName = $this->caseFields[static::KEY_EXPECTED_SKILL] ?? null;
        return $expectedSkillName !== null ? (string)$expectedSkillName : null;
    }

    /**
     * `forbiddenSkills` of a selection case: skills the agent must not load (a list or one name); empty when it names
     * none.
     *
     * @return string[]
     */
    public function getForbiddenSkillNames(): array
    {
        $forbiddenSkillNames = $this->caseFields[static::KEY_FORBIDDEN_SKILLS] ?? [];
        $forbiddenSkillNames = is_array($forbiddenSkillNames) ? $forbiddenSkillNames : [$forbiddenSkillNames];
        return array_values(array_filter(array_map(static fn(mixed $skillName): string => trim((string)$skillName), $forbiddenSkillNames), static fn(string $skillName): bool => $skillName !== ''));
    }

    /**
     * `access`: how the case declares it touches its agent space in a suite — `read`, `write` or `exclusive`, lower
     * case and trimmed; empty when the case declares nothing (or something else).
     */
    public function getDeclaredSuiteAccess(): string
    {
        $declaredAccess = strtolower(trim((string)($this->caseFields[static::KEY_ACCESS] ?? '')));
        $declarableAccesses = [AgentEvalSuiteTask::ACCESS_READ, AgentEvalSuiteTask::ACCESS_WRITE, AgentEvalSuiteTask::ACCESS_EXCLUSIVE];
        return in_array($declaredAccess, $declarableAccesses, true) ? $declaredAccess : '';
    }

    /** `exclusive`: true = a writing case runs alone on its agent space. */
    public function isDeclaredExclusive(): bool
    {
        return ($this->caseFields[static::KEY_EXCLUSIVE] ?? false) === true;
    }

    /** The files the case puts into its own workspace before the agent runs; empty without `scratchFiles`. */
    public function getScratchFiles(): AgentEvalScratchFiles
    {
        return AgentEvalScratchFiles::fromFixtureEntries($this->caseFields[static::KEY_SCRATCH_FILES] ?? null);
    }

    /** `lane`: the resource group the case names itself, trimmed; empty when it names none. */
    public function getDeclaredLane(): string
    {
        return trim((string)($this->caseFields[static::KEY_LANE] ?? ''));
    }

    /**
     * Whether the case expects a tool that may change something (any-of `expect.tool`, all-of `expect.requiredTools`):
     * a name that is neither one of the loop's own tools (`$loopToolNames`, they change the conversation only) nor a
     * tool that declares itself read-only.
     *
     * @param string[] $loopToolNames the loop's own tools
     */
    public function expectsChangingTool(array $loopToolNames): bool
    {
        $expectedToolNames = array_merge($this->getExpectedAnyOfToolNames(), $this->getExpectedRequiredToolNames());
        foreach ($expectedToolNames as $expectedToolName) {
            if ($expectedToolName !== '' && !in_array($expectedToolName, $loopToolNames, true) && !MCPToolEffectCatalog::changesNoSharedDataForToolName($expectedToolName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * How the case touches its live agent space in a suite ({@see AgentEvalSuiteTask}'s `ACCESS_*`). The case's own
     * `access` decides when it is given. Else a case WRITES when it carries a `restore` block, is a scripted flow
     * (`turns`), checks a state after the run, is flagged `sequential`, or expects a tool that may change something
     * ({@see expectsChangingTool()}) — a case that cleans up after itself writes without a restore block. A writing case
     * is EXCLUSIVE when it is a scripted flow, declares `exclusive: true`, or its fixture is exclusive. Every other case
     * READS (and runs with every write tool refused: a judged case whose rubric needs a write declares `access: write`).
     *
     * @param bool $fixtureIsExclusive whether the case's fixture runs its writing cases alone on their agent space
     * @param string[] $loopToolNames the loop's own tools
     */
    public function resolveSuiteAccess(bool $fixtureIsExclusive, array $loopToolNames): string
    {
        $declaredAccess = $this->getDeclaredSuiteAccess();
        if ($declaredAccess !== '') {
            return $declaredAccess;
        }
        $writes = $this->getRestore() !== null
            || $this->hasTurns()
            || $this->getStateAfterRun() !== null
            || $this->isSequential()
            || $this->expectsChangingTool($loopToolNames);
        if (!$writes) {
            return AgentEvalSuiteTask::ACCESS_READ;
        }
        if ($this->hasTurns() || $this->isDeclaredExclusive() || $fixtureIsExclusive) {
            return AgentEvalSuiteTask::ACCESS_EXCLUSIVE;
        }
        return AgentEvalSuiteTask::ACCESS_WRITE;
    }

    /**
     * The resource group of the case: its own `lane` when it names one, else the group of its fixture
     * (`$resourceGroupByFixture`, fixture name → group), else the fixture itself.
     *
     * @param string $fixtureName the file name of the case's fixture without `.json`
     * @param string[] $resourceGroupByFixture fixture name → resource group
     */
    public function resolveResourceKey(string $fixtureName, array $resourceGroupByFixture): string
    {
        $declaredLane = $this->getDeclaredLane();
        if ($declaredLane !== '') {
            return $declaredLane;
        }
        return (string)($resourceGroupByFixture[$fixtureName] ?? $fixtureName);
    }

    /**
     * The tool names the executor refuses during the agent's run of this case: the case's `blockedTools`, then every
     * external-write tool the case does not name in `allowExternalWrites`. Order kept, duplicates dropped.
     *
     * @param string[] $externalWriteToolNames the tools whose completed call writes to a real external profile
     * @return string[]
     */
    public function resolveBlockedToolNames(array $externalWriteToolNames): array
    {
        $blockedExternalWriteToolNames = array_diff(array_map('strval', $externalWriteToolNames), $this->getAllowedExternalWriteToolNames());
        return array_values(array_unique(array_merge($this->getBlockedToolNames(), $blockedExternalWriteToolNames)));
    }

    /**
     * `allowExternalWrites`: the external-write tools the case may call (empty when it declares none).
     *
     * @return string[]
     */
    public function getAllowedExternalWriteToolNames(): array
    {
        return array_values(array_map('strval', (array)($this->caseFields[static::KEY_ALLOW_EXTERNAL_WRITES] ?? [])));
    }

    /**
     * The names in `allowExternalWrites` that are not external-write tools — a typo there would leave the intended
     * tool blocked and the case failing for the wrong reason.
     *
     * @param string[] $externalWriteToolNames the tools whose completed call writes to a real external profile
     * @return string[]
     */
    public function findUnknownAllowedExternalWriteToolNames(array $externalWriteToolNames): array
    {
        return array_values(array_diff($this->getAllowedExternalWriteToolNames(), array_map('strval', $externalWriteToolNames)));
    }
}
