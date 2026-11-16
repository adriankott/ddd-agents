<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\Base\Entities\ValueObject;
use RuntimeException;

/**
 * The comparison script of one MCP package: a committed JSON file the command `app:mcp:compare-tool-calls` reads. Pure.
 *
 * Shape:
 * ```json
 * {
 *   "package": "NotesMCPPackage",                      // short class name (or class name) of the package under test
 *   "additionalPackages": [],                          // other packages offered beside it (a case calling their tools)
 *   "agentSpaceId": 42,                                // the agent space the run works in (optional: account level)
 *   "description": "…",
 *   "derivedMomentColumns": ["table.column"],          // statement audit: columns holding now + x (read <derived-clock>)
 *   "derivedMomentKeys": ["scheduledAt"],              // keys (message bodies, JSON values) holding now + x (<derived-clock>)
 *   "durationKeys": ["elapsedMs"],                     // keys holding a wall-clock duration (<duration>)
 *   "wallClockTextPrefixes": ["(started "],            // text right before an unswitched site writes a wall-clock H:i (<clock>)
 *   "opaqueTokenKeys": ["idempotencyKey"],             // keys holding a token derived from a harness id (<key#n> per distinct value)
 *   "conversations": {                                 // one harness conversation each, created once per run
 *     "chat":  {"ownerRows": ["…"]},
 *     "plan":  {"purposeName": "…", "ownerRows": ["…"], "agentWorkingState": {"taskPlan": {…}}},
 *     "child": {"childOf": "plan"},
 *     "run":   {"scheduleId": -1}
 *   },
 *   "fixture": [ {"tool": "…", "arguments": {…}, "conversation": "chat", "expectsError": false} ],   // harness calls before the first case
 *   "cases": [
 *     {"id": "unique.case.id", "tool": "getNotes", "arguments": {}, "conversation": "chat",
 *      "provenance": "agent|evalHarness|none", "before": [ {"tool": "…", "arguments": {…}, "conversation": "chat"} ],
 *      "note": "what the case shows"}
 *   ]
 * }
 * ```
 * The string {@see ToolCallComparisonToolCall::AGENT_SPACE_ID_PLACEHOLDER} in any argument is replaced with the id of
 * the run's agent space. An application whose runs prepare their agent space otherwise (a fresh space per run) reads
 * its own keys of the decoded script in {@see readHostScriptFields()} and adds its rules in
 * {@see findHostValidationErrors()} and {@see findHostToolCallErrors()}.
 *
 * @phpstan-consistent-constructor
 */
class ToolCallComparisonScript extends ValueObject
{
    /** The script name the recording carries (the file's base name). */
    public string $scriptName = '';

    /** The package under test as the script names it (short name or class name). */
    public string $packageName = '';

    /** @var string[] packages offered beside the package under test, as the script names them */
    public array $additionalPackageNames = [];

    /** The agent space the run works in; null = the run works at account level (an application may prepare its own). */
    public ?int $agentSpaceId = null;

    public string $description = '';

    /** @var string[] `table.column` of columns holding moments derived from the wall clock */
    public array $derivedMomentColumns = [];

    /** @var string[] keys of message bodies and JSON values that hold a moment derived from the wall clock (now + x) */
    public array $derivedMomentKeys = [];

    /** @var string[] keys of message bodies and JSON values that hold a wall-clock duration (how long a call took) */
    public array $durationKeys = [];

    /**
     * @var string[] literal text right before which an unswitched site writes a wall-clock time of day into a text; the
     *      `H:i` after it reads `<clock>` when the run's wall clock wrote it
     *      ({@see \DDD\Domain\Base\Entities\RecordedRuns\RecordedRunWallClockNormalization::normalizeClockTextPhrases()})
     */
    public array $wallClockTextPrefixes = [];

    /**
     * @var string[] keys (JSON values, message bodies, JSON written into text) whose value is a token derived from a
     *      harness id; compared by which values are equal, never by the bytes
     *      ({@see ToolCallComparisonIdPlaceholders::normalizeOpaqueToken()})
     */
    public array $opaqueTokenKeys = [];

    /** The harness conversations by name, in script order. */
    public ToolCallComparisonConversationFixtures $conversationFixtures;

    /** The harness calls run before the first case. */
    public ToolCallComparisonToolCalls $fixtureSteps;

    /** The compared cases, in order. */
    public ToolCallComparisonToolCalls $cases;

    /** The sha-256 of the script's text: a replay refuses a script that differs from the recorded one. */
    public string $contentHash = '';

    /** @var array The decoded script object as the file holds it */
    protected array $scriptFields = [];

    public function __construct()
    {
        parent::__construct();
        $this->conversationFixtures = ToolCallComparisonConversationFixtures::newInstance();
        $this->fixtureSteps = ToolCallComparisonToolCalls::newInstance();
        $this->cases = ToolCallComparisonToolCalls::newInstance();
    }

    /**
     * @throws RuntimeException when the text is no script object
     */
    public static function fromJson(string $scriptJson, string $scriptName): static
    {
        $scriptFields = json_decode($scriptJson, true);
        if (!is_array($scriptFields)) {
            throw new RuntimeException("{$scriptName} is no JSON object: " . json_last_error_msg());
        }
        $toolCallComparisonScript = static::newInstance();
        $toolCallComparisonScript->scriptName = $scriptName;
        $toolCallComparisonScript->contentHash = hash('sha256', $scriptJson);
        $toolCallComparisonScript->scriptFields = $scriptFields;
        $toolCallComparisonScript->readScriptFields();
        $toolCallComparisonScript->readHostScriptFields();
        return $toolCallComparisonScript;
    }

    /**
     * What is wrong with the script, one line each; empty when it can run.
     *
     * @param string[] $availableToolNames the tools of the package under test and the additional packages
     * @param int $runningAccountId the account the run would run as (0: none known)
     * @return string[]
     */
    public function findValidationErrors(array $availableToolNames, int $runningAccountId = 0): array
    {
        $validationErrors = [];
        if ($this->packageName === '') {
            $validationErrors[] = 'no "package"';
        }
        $validationErrors = array_merge($validationErrors, $this->findHostValidationErrors($runningAccountId));
        if ($this->cases->isEmpty()) {
            $validationErrors[] = 'no "cases"';
        }
        foreach ($this->conversationFixtures->getElements() as $conversationFixture) {
            if ($conversationFixture->childOf === null) {
                continue;
            }
            $rootFixture = $this->conversationFixtures->findFixtureByName($conversationFixture->childOf);
            if ($rootFixture === null) {
                $validationErrors[] = "conversation {$conversationFixture->name}: childOf names no conversation ({$conversationFixture->childOf})";
            } elseif ($rootFixture->childOf !== null) {
                $validationErrors[] = "conversation {$conversationFixture->name}: childOf must name a root conversation";
            }
        }
        $seenCaseIds = [];
        foreach (array_values($this->cases->getElements()) as $caseIndex => $case) {
            $caseLabel = $case->id !== '' ? $case->id : 'case #' . ($caseIndex + 1);
            if ($case->id === '') {
                $validationErrors[] = "{$caseLabel}: no \"id\"";
            } elseif (isset($seenCaseIds[$case->id])) {
                $validationErrors[] = "{$caseLabel}: the id is used twice";
            }
            $seenCaseIds[$case->id] = true;
            $validationErrors = array_merge($validationErrors, $this->findToolCallErrors($caseLabel, $case, $availableToolNames));
            foreach ($case->beforeSteps->getElements() as $beforeStep) {
                $validationErrors = array_merge($validationErrors, $this->findToolCallErrors($beforeStep->id, $beforeStep, $availableToolNames));
            }
        }
        foreach ($this->fixtureSteps->getElements() as $fixtureStep) {
            $validationErrors = array_merge($validationErrors, $this->findToolCallErrors($fixtureStep->id, $fixtureStep, $availableToolNames));
        }
        return $validationErrors;
    }

    /** @return string[] the case ids, in order */
    public function getCaseIds(): array
    {
        return $this->cases->getIds();
    }

    /** @return string[] the labels of the harness calls (fixture and `before` calls), in script order */
    public function getHarnessStepIds(): array
    {
        $harnessStepIds = $this->fixtureSteps->getIds();
        foreach ($this->cases->getElements() as $case) {
            $harnessStepIds = array_merge($harnessStepIds, $case->beforeSteps->getIds());
        }
        return $harnessStepIds;
    }

    /** Reads the keys of the decoded script every comparison knows. */
    protected function readScriptFields(): void
    {
        $scriptFields = $this->scriptFields;
        $this->packageName = (string)($scriptFields['package'] ?? '');
        $this->additionalPackageNames = array_values(array_map('strval', (array)($scriptFields['additionalPackages'] ?? [])));
        $this->agentSpaceId = isset($scriptFields['agentSpaceId']) ? (int)$scriptFields['agentSpaceId'] : null;
        $this->description = (string)($scriptFields['description'] ?? '');
        $this->derivedMomentColumns = array_values(array_map('strval', (array)($scriptFields['derivedMomentColumns'] ?? [])));
        $this->derivedMomentKeys = array_values(array_map('strval', (array)($scriptFields['derivedMomentKeys'] ?? [])));
        $this->durationKeys = array_values(array_map('strval', (array)($scriptFields['durationKeys'] ?? [])));
        $this->wallClockTextPrefixes = array_values(array_map('strval', (array)($scriptFields['wallClockTextPrefixes'] ?? [])));
        $this->opaqueTokenKeys = array_values(array_map('strval', (array)($scriptFields['opaqueTokenKeys'] ?? [])));
        foreach ((array)($scriptFields['conversations'] ?? []) as $conversationName => $conversationFields) {
            $conversationFixture = ToolCallComparisonConversationFixture::fromScriptFields((string)$conversationName, (array)$conversationFields);
            $this->conversationFixtures->add($conversationFixture);
        }
        foreach (array_values((array)($scriptFields['fixture'] ?? [])) as $fixtureStepIndex => $fixtureStepFields) {
            $fixtureStep = ToolCallComparisonToolCall::fromScriptFields((array)$fixtureStepFields, ToolCallComparisonToolCall::PROVENANCE_EVAL_HARNESS);
            $fixtureStep->id = 'fixture#' . ($fixtureStepIndex + 1);
            $this->fixtureSteps->add($fixtureStep);
        }
        foreach ((array)($scriptFields['cases'] ?? []) as $caseFields) {
            $case = ToolCallComparisonToolCall::fromScriptFields((array)$caseFields);
            foreach (array_values($case->beforeSteps->getElements()) as $beforeStepIndex => $beforeStep) {
                $beforeStep->id = $case->id . '#before' . ($beforeStepIndex + 1);
            }
            $this->cases->add($case);
        }
    }

    /**
     * Reads the application's own keys of the decoded script ({@see $scriptFields}) into its own fields. The default:
     * none.
     */
    protected function readHostScriptFields(): void
    {
    }

    /**
     * The application's own rules of the script, one line each, checked right after the package is checked. The
     * default: none.
     *
     * @param int $runningAccountId the account the run would run as (0: none known)
     * @return string[]
     */
    protected function findHostValidationErrors(int $runningAccountId): array
    {
        return [];
    }

    /**
     * @param string[] $availableToolNames
     * @return string[]
     */
    protected function findToolCallErrors(string $callLabel, ToolCallComparisonToolCall $toolCall, array $availableToolNames): array
    {
        $toolCallErrors = [];
        if (!in_array($toolCall->toolName, $availableToolNames, true)) {
            $toolCallErrors[] = "{$callLabel}: tool \"{$toolCall->toolName}\" is no tool of the script's packages";
        }
        if ($this->conversationFixtures->findFixtureByName($toolCall->conversationName) === null) {
            $toolCallErrors[] = "{$callLabel}: \"conversation\" must name a declared conversation (every call carries one)";
        }
        if (!in_array($toolCall->provenanceKind, ToolCallComparisonToolCall::PROVENANCE_KINDS, true)) {
            $toolCallErrors[] = "{$callLabel}: provenance \"{$toolCall->provenanceKind}\" is none of " . implode(', ', ToolCallComparisonToolCall::PROVENANCE_KINDS);
        }
        return array_merge($toolCallErrors, $this->findHostToolCallErrors($callLabel, $toolCall));
    }

    /**
     * The application's own rules of one call, one line each (its arguments may name only ids the application allows).
     * The default: none.
     *
     * @return string[]
     */
    protected function findHostToolCallErrors(string $callLabel, ToolCallComparisonToolCall $toolCall): array
    {
        return [];
    }
}
