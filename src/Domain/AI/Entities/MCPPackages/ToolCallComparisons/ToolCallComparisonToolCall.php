<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One tool call of a tool-call comparison script: a compared CASE, or a harness step (the script's `fixture` and a
 * case's `before` calls, which bring the agent space and the conversations into the state the case needs and are not
 * compared). Pure.
 *
 * Provenance kinds:
 * - `agent` (the default): the provenance the agent loop builds — the conversation, its newest owner row, its root,
 *   its schedule, the agent space;
 * - `evalHarness`: {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance::forEvalHarness()} with
 *   the conversation — only when a case asks for it, because a tool may skip its widgets and notes then;
 * - `none`: no provenance at all (the shape of a call over HTTP); the arguments name the agent space themselves.
 *
 * The literal argument value {@see AGENT_SPACE_ID_PLACEHOLDER} is replaced with the id the run names its agent space
 * by; an application whose committed scripts carry another placeholder redeclares the constant in its subclass.
 *
 * @phpstan-consistent-constructor
 */
class ToolCallComparisonToolCall extends ValueObject
{
    public const string PROVENANCE_AGENT = 'agent';
    public const string PROVENANCE_EVAL_HARNESS = 'evalHarness';
    public const string PROVENANCE_NONE = 'none';

    /** @var string[] Every provenance kind a call may state */
    public const array PROVENANCE_KINDS = [self::PROVENANCE_AGENT, self::PROVENANCE_EVAL_HARNESS, self::PROVENANCE_NONE];

    /** The literal argument value the harness replaces with the id of the run's agent space. */
    public const string AGENT_SPACE_ID_PLACEHOLDER = '{{agentSpaceId}}';

    /** The case id (unique in the script) or, for a harness step, the label the harness gives it. */
    public string $id = '';

    public string $toolName = '';

    /** The fixture conversation the call runs in. */
    public string $conversationName = '';

    public string $provenanceKind = self::PROVENANCE_AGENT;

    /** What the case shows (documentation only). */
    public string $note = '';

    /**
     * A harness step that is MEANT to answer an error (a warm-up call refused on purpose, after the reads it is run
     * for): its error is no failure, and a step that answers no error is reported. Ignored on a case.
     */
    public bool $expectsError = false;

    /** Harness steps run before this case (eval-harness provenance unless they say otherwise), not compared. */
    public ToolCallComparisonToolCalls $beforeSteps;

    /** @var array The arguments as the script states them (as the model would send them): the decoded `arguments` object */
    protected array $arguments = [];

    public function __construct()
    {
        parent::__construct();
        $this->beforeSteps = ToolCallComparisonToolCalls::newInstance();
    }

    /**
     * @param array $callFields the decoded object of the script for this call
     */
    public static function fromScriptFields(array $callFields, string $defaultProvenanceKind = self::PROVENANCE_AGENT): static
    {
        $toolCallComparisonToolCall = static::newInstance();
        $toolCallComparisonToolCall->id = (string)($callFields['id'] ?? '');
        $toolCallComparisonToolCall->toolName = (string)($callFields['tool'] ?? '');
        $toolCallComparisonToolCall->arguments = is_array($callFields['arguments'] ?? null) ? $callFields['arguments'] : [];
        $toolCallComparisonToolCall->conversationName = (string)($callFields['conversation'] ?? '');
        $toolCallComparisonToolCall->provenanceKind = (string)($callFields['provenance'] ?? $defaultProvenanceKind);
        $toolCallComparisonToolCall->note = (string)($callFields['note'] ?? '');
        $toolCallComparisonToolCall->expectsError = (bool)($callFields['expectsError'] ?? false);
        foreach ((array)($callFields['before'] ?? []) as $beforeStepFields) {
            $beforeStep = static::fromScriptFields((array)$beforeStepFields, static::PROVENANCE_EVAL_HARNESS);
            $toolCallComparisonToolCall->beforeSteps->add($beforeStep);
        }
        return $toolCallComparisonToolCall;
    }

    /**
     * The arguments as the script states them, the placeholder unreplaced.
     *
     * @return array the decoded `arguments` object of the script
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * The arguments the executor receives: {@see AGENT_SPACE_ID_PLACEHOLDER} replaced with `$agentSpaceIdOfRun` at any
     * depth.
     *
     * @return array the arguments as the tool executor takes them
     */
    public function buildArgumentsForAgentSpace(int $agentSpaceIdOfRun): array
    {
        return $this->replaceAgentSpaceIdPlaceholder($this->arguments, $agentSpaceIdOfRun);
    }

    /**
     * @param array $arguments arguments as the tool executor takes them
     * @return array
     */
    protected function replaceAgentSpaceIdPlaceholder(array $arguments, int $agentSpaceIdOfRun): array
    {
        foreach ($arguments as $argumentName => $argumentValue) {
            if (is_array($argumentValue)) {
                $arguments[$argumentName] = $this->replaceAgentSpaceIdPlaceholder($argumentValue, $agentSpaceIdOfRun);
            } elseif ($argumentValue === static::AGENT_SPACE_ID_PLACEHOLDER) {
                $arguments[$argumentName] = $agentSpaceIdOfRun;
            }
        }
        return $arguments;
    }
}
