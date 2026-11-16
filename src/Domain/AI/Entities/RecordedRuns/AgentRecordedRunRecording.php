<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\RecordedRuns;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunArgusRequest;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison;
use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunIdNormalization;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultRecording;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecording;
use RuntimeException;

/**
 * The recording of a recorded run, the stored document a replay answers from and compares with. Its top-level keys,
 * in stored order: `version`, `toolsRunLive`, `recordedConversationId`, `scopeIds`, `idSeed`, then the harness's own
 * fields ({@see AgentRecordedRunFields}: `harness`, the settings, the turns …), then `idNormalization`,
 * `createdIdReadReport`, `calls`, `toolResults`, `snapshot`. A harness may append further fields after the snapshot
 * ({@see withAppendedRunFields()}). The scope names and the field names are the harness's: the package never renames a
 * stored key, so a recording replays across builds as long as its version is {@see RECORDING_VERSION}.
 *
 * @phpstan-consistent-constructor
 */
class AgentRecordedRunRecording extends ValueObject
{
    /**
     * Version 3: calls carry their owner turn and kind and a transport failure as the exception it was (version 2
     * matched across turns and stored a timeout as a status-0 response; version 1 held the root's rows only).
     */
    public const int RECORDING_VERSION = 3;

    /** @var array<string, mixed> The stored document, in stored key order */
    protected array $storedRecording = [];

    /**
     * The recording of a finished run.
     *
     * @param bool $toolsRunLive whether the tools ran live (their in-tool calls recorded) instead of being played
     * @param int $recordedConversationId the root conversation of the run
     * @param array<string, int|string|null> $scopeIdsByName the scope ids the run names as its own
     * @param string $idSeed the seed of the run's pinned id source ('' without one)
     * @param AgentRecordedRunFields $runFields the harness's own fields
     * @param AgentRecordedRunIdNormalization $idNormalization the run's id normalization
     * @param AgentRecordedRunCreatedIdRead $createdIdRead the run's read of its created ids
     * @param ArgusTransportRecording|null $argusTransportRecording the recorded calls (none without a recorder)
     * @param ToolResultRecording|null $toolResultRecording the recorded tool results (none without a recorder)
     * @param AgentRecordedRunSnapshot $agentRecordedRunSnapshot the normalized snapshot of the run
     * @return static
     */
    public static function forRun(
        bool $toolsRunLive,
        int $recordedConversationId,
        array $scopeIdsByName,
        string $idSeed,
        AgentRecordedRunFields $runFields,
        AgentRecordedRunIdNormalization $idNormalization,
        AgentRecordedRunCreatedIdRead $createdIdRead,
        ?ArgusTransportRecording $argusTransportRecording,
        ?ToolResultRecording $toolResultRecording,
        AgentRecordedRunSnapshot $agentRecordedRunSnapshot
    ): static {
        // `+` keeps the first of two equal keys: a run field never replaces a key of the recording itself
        return static::fromStoredRecording([
            'version' => self::RECORDING_VERSION,
            'toolsRunLive' => $toolsRunLive,
            'recordedConversationId' => $recordedConversationId,
            'scopeIds' => $scopeIdsByName,
            'idSeed' => $idSeed,
        ] + $runFields->toArray() + [
            'idNormalization' => $idNormalization->toArray(),
            'createdIdReadReport' => $createdIdRead->toArray(),
            'calls' => $argusTransportRecording !== null ? $argusTransportRecording->toStorageArray() : [],
            'toolResults' => $toolResultRecording !== null ? $toolResultRecording->toStoredEntries() : [],
            'snapshot' => $agentRecordedRunSnapshot->toArray(),
        ]);
    }

    /**
     * A recording as read from its file, checked: a run recording of {@see RECORDING_VERSION} with a snapshot and an
     * id normalization.
     *
     * @param string $recordingJson
     * @param string $recordingName the name the refusal names (the file)
     * @return static
     * @throws RuntimeException when the text is no replayable recording
     */
    public static function fromJson(string $recordingJson, string $recordingName): static
    {
        $storedRecording = json_decode($recordingJson, true);
        if (!is_array($storedRecording) || !isset($storedRecording['calls'])) {
            throw new RuntimeException("{$recordingName} is no run recording");
        }
        $recordingVersion = (int)($storedRecording['version'] ?? 1);
        if ($recordingVersion !== self::RECORDING_VERSION) {
            throw new RuntimeException("{$recordingName} is a version-{$recordingVersion} recording; this build replays version " . self::RECORDING_VERSION . ' only — record it again');
        }
        if (!isset($storedRecording['snapshot'], $storedRecording['idNormalization'])) {
            throw new RuntimeException("{$recordingName} has no snapshot or id normalization — record it again");
        }
        return static::fromStoredRecording($storedRecording);
    }

    /**
     * @param array<string, mixed> $storedRecording the stored document, unchecked
     * @return static
     */
    public static function fromStoredRecording(array $storedRecording): static
    {
        $agentRecordedRunRecording = static::newInstance();
        $agentRecordedRunRecording->storedRecording = $storedRecording;
        return $agentRecordedRunRecording;
    }

    /**
     * The recording with the harness's fields appended after the snapshot; a field present already keeps its place and
     * gets the new value.
     *
     * @param AgentRecordedRunFields $runFields
     * @return static
     */
    public function withAppendedRunFields(AgentRecordedRunFields $runFields): static
    {
        return static::fromStoredRecording(array_merge($this->storedRecording, $runFields->toArray()));
    }

    /**
     * Why a replay must not run with these settings, or null: the recording comes from another harness, or a setting
     * the caller passed differs from the recorded one (the recording wins — a different value is refused, not ignored).
     *
     * @param string $harness the harness that replays
     * @param array<string, mixed> $settingsPassedByTheCaller stored field → the value the caller passed (null = not
     *                                                        passed); a scalar or a list or map of scalars
     * @return string|null
     */
    public function findReplayRefusal(string $harness, array $settingsPassedByTheCaller): ?string
    {
        $recordedHarness = $this->storedRecording[AgentRecordedRunFields::FIELD_HARNESS] ?? null;
        if ($recordedHarness !== $harness) {
            return 'the recording comes from ' . ($recordedHarness ?? 'an unknown harness') . ", not from {$harness}";
        }
        foreach ($settingsPassedByTheCaller as $recordingKey => $passedValue) {
            if ($passedValue === null) {
                continue;
            }
            $recordedValue = $this->storedRecording[$recordingKey] ?? null;
            $isEqual = is_array($passedValue) || is_array($recordedValue)
                ? (array)$passedValue == (array)$recordedValue
                : (string)$passedValue === (string)$recordedValue;
            if (!$isEqual) {
                return "{$recordingKey} differs from the recording (recorded " . json_encode($recordedValue) . ', passed ' . json_encode($passedValue) . ') — omit it or pass the recorded value';
            }
        }
        return null;
    }

    /**
     * @param string $field
     * @return mixed the value of a top-level field (a harness's own field among them); null when absent
     */
    public function getRunFieldValue(string $field): mixed
    {
        return $this->storedRecording[$field] ?? null;
    }

    /**
     * @return string[] the owner turns the harness stored (`turns`)
     */
    public function getTurns(): array
    {
        return array_values(array_map('strval', (array)($this->storedRecording['turns'] ?? [])));
    }

    /**
     * @return bool whether the turns took the worker path (`async`)
     */
    public function isAsync(): bool
    {
        return (bool)($this->storedRecording['async'] ?? false);
    }

    /**
     * @return bool whether the tools ran live in the recorded run
     */
    public function toolsRunLive(): bool
    {
        return (bool)($this->storedRecording['toolsRunLive'] ?? false);
    }

    /**
     * @return string the seed of the recorded run's pinned id source; '' for a recording made without one
     */
    public function getIdSeed(): string
    {
        return (string)($this->storedRecording['idSeed'] ?? '');
    }

    /**
     * @return array<string, int|string|null> the scope ids the recorded run named as its own
     */
    public function getScopeIds(): array
    {
        return (array)($this->storedRecording['scopeIds'] ?? []);
    }

    /**
     * @return AgentRecordedRunSnapshot
     */
    public function getSnapshot(): AgentRecordedRunSnapshot
    {
        return AgentRecordedRunSnapshot::fromRowsBySection((array)($this->storedRecording['snapshot'] ?? []));
    }

    /**
     * @return AgentRecordedRunIdNormalization the recorded run's id normalization, as stored
     */
    public function getIdNormalization(): AgentRecordedRunIdNormalization
    {
        return AgentRecordedRunIdNormalization::fromArray((array)($this->storedRecording['idNormalization'] ?? []));
    }

    /**
     * @return array<string, int[]> per table: the ids the recorded run created, as its id normalization stored them
     */
    public function getRecordedCreatedIdsByTable(): array
    {
        return (array)($this->storedRecording['idNormalization']['createdIdsByTable'] ?? []);
    }

    /**
     * @return AgentRecordedRunCreatedIdRead the recorded run's read of its created ids
     */
    public function getCreatedIdRead(): AgentRecordedRunCreatedIdRead
    {
        return AgentRecordedRunCreatedIdRead::fromStoredRead((array)($this->storedRecording['createdIdReadReport'] ?? []));
    }

    /**
     * The transport player of a replay: it answers every Argus call from the recorded calls — with live tools also the
     * calls the tools make, from the recorded in-tool calls.
     *
     * @param bool $toolsRunLive
     * @return ArgusTransportRecording
     */
    public function buildTransportPlayer(bool $toolsRunLive): ArgusTransportRecording
    {
        $storedCalls = (array)($this->storedRecording['calls'] ?? []);
        return $toolsRunLive ? ArgusTransportRecording::forPlayingWithLiveTools($storedCalls) : ArgusTransportRecording::forPlaying($storedCalls);
    }

    /**
     * The tool-result player of a replay whose tools do not run: it answers every tool call from the recorded results.
     *
     * @return ToolResultRecording
     */
    public function buildToolResultPlayer(): ToolResultRecording
    {
        return ToolResultRecording::forPlaying((array)($this->storedRecording['toolResults'] ?? []));
    }

    /**
     * A replay compared with this recording: the snapshot sections, then the requests the replay sent to Argus (each
     * against the recorded call that answered it; a played tool's own calls are no part of a replay that plays the
     * tool's result), then the recorded calls and tool results the replay never asked for.
     *
     * @param AgentRecordedRunSnapshot $replaySnapshot
     * @param array<string, int|string|null> $replayScopeIdsByName the scope ids the replay created for itself
     * @param AgentRecordedRunIdNormalization $replayIdNormalization
     * @param ArgusTransportRecording|null $replayTransportRecording the replay's player
     * @param ToolResultRecording|null $replayToolResultRecording the replay's tool-result player (ignored with live tools)
     * @param bool $toolsRunLive
     * @return AgentRecordedRunComparison
     */
    public function compareWithReplay(
        AgentRecordedRunSnapshot $replaySnapshot,
        array $replayScopeIdsByName,
        AgentRecordedRunIdNormalization $replayIdNormalization,
        ?ArgusTransportRecording $replayTransportRecording,
        ?ToolResultRecording $replayToolResultRecording,
        bool $toolsRunLive
    ): AgentRecordedRunComparison {
        // the recording's normalization continues where its snapshot left it; a played answer carries the RECORDING's
        // scope ids, the replay's own requests its own: both read as the same placeholder
        $expectedIdNormalization = $this->getIdNormalization();
        $expectedIdNormalization->addScopeIds($replayScopeIdsByName);
        $comparableRecordedRequests = [];
        foreach ((array)($this->storedRecording['calls'] ?? []) as $position => $recordedCall) {
            // positions are kept
            if ($toolsRunLive || !($recordedCall['duringTool'] ?? false)) {
                $comparableRecordedRequests[$position] = AgentRecordedRunArgusRequest::forEndpointAndRequestBody((string)$recordedCall['endpoint'], (string)$recordedCall['requestBody']);
            }
        }
        $unusedRecordedCalls = $replayTransportRecording?->unusedEntries() ?? [];
        if (!$toolsRunLive) {
            foreach ($replayToolResultRecording?->unusedEntries() ?? [] as $position => $toolName) {
                $unusedRecordedCalls['tool result ' . $position] = $toolName;
            }
        }
        return AgentRecordedRunComparison::buildComparison(
            $this->getSnapshot()->toArray(),
            $replaySnapshot->toArray(),
            $comparableRecordedRequests,
            $replayTransportRecording !== null ? AgentRecordedRunComparison::buildPlayedArgusRequests($replayTransportRecording->playedRequests) : [],
            $expectedIdNormalization,
            $replayIdNormalization,
            $unusedRecordedCalls
        );
    }

    /**
     * The stored document of the recording, in stored key order.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->storedRecording;
    }

    /**
     * The stored document as the recording file holds it: pretty-printed JSON, unicode and slashes unescaped.
     *
     * @return string
     */
    public function buildRecordingFileContent(): string
    {
        return (string)json_encode($this->storedRecording, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
