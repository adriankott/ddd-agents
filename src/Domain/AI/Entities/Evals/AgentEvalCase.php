<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One case of an evaluation fixture, built from the decoded JSON object of the case.
 *
 * The generic keys of the fixture format have typed accessors here: `id`, `prompt`, `followUpPrompts`,
 * `followUpIdleMinutes`, `turns`, `maxTurns`, `maxStepsPerTurn`, `timeoutSeconds`, `purposeName`, `reportChannel`,
 * `scheduledRun`, `agentSpaceId`, `blockedTools`, `blockedToolSimulatedErrors`, `allowExternalWrites`, `sequential`,
 * `executionMode`, `requiresVision`, `requires`, `runsOnIneligibleScope`, `runsWithoutCapability`, the `expect` block
 * (with its state check `expect.stateAfterRun`) and the `restore` block. Every other key is an application's own: it is
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

    /** `agentSpaceId`: the stored agent space the case runs in; null when the case names none. */
    public function getAgentSpaceId(): ?int
    {
        $agentSpaceId = (int)($this->caseFields[static::KEY_AGENT_SPACE_ID] ?? 0);
        return $agentSpaceId > 0 ? $agentSpaceId : null;
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
