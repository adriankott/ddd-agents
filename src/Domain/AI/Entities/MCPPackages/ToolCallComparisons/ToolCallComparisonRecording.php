<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecording;
use RuntimeException;

/**
 * The recording of one package's comparison script: written by `app:mcp:compare-tool-calls --record`, read by
 * `--replay-and-compare`. It carries what the replay must pin to be byte-comparable — the pinned moment, the id seed,
 * the script's hash, the recorded ids of the harness rows (the run's agent space, the conversations) — the per-case
 * outcomes, every Argus call and every call the application's own recorders kept (the replay answers them from here),
 * and the name of the definitions dump written beside it. Pure.
 *
 * The stored key of every field comes from {@see getStoredKeyByField()}: an application whose recordings carry other
 * key names maps them there, so a recording it made before stays readable and is written byte-identically.
 *
 * @phpstan-consistent-constructor
 */
class ToolCallComparisonRecording extends ValueObject
{
    /**
     * Version 2: the run's own agent space, the harness ids by placeholder, the in-tool Argus requests per case.
     * Version 3: the run's wall-clock rule on dispatches and JSON payloads, the pinned moment set before the run.
     */
    public const int RECORDING_VERSION = 3;

    /** The command that writes and reads the recording; the stored document names it. */
    public const string HARNESS = 'app:mcp:compare-tool-calls';

    /** @var string[] The fields of the stored document, in their stored order */
    public const array STORED_FIELDS_IN_ORDER = [
        'harness',
        'version',
        'scriptName',
        'scriptHash',
        'packageClass',
        'comparisonAgentSpaceId',
        'harnessIdsByPlaceholder',
        'pinnedMoment',
        'idSeed',
        'recordedConversationIds',
        'definitionsDumpFileName',
        'caseOutcomes',
        'argusCalls',
        'hostRecordedCalls',
    ];

    public string $scriptName = '';

    public string $scriptHash = '';

    /** The class name of the package under test. */
    public string $packageClass = '';

    /** The id the recording run named its agent space by (information only; the replay prepares its own). */
    public int $comparisonAgentSpaceId = 0;

    /** @var string[] placeholder → the id of that harness row in the recording run (the replay pairs its own ids with them) */
    public array $harnessIdsByPlaceholder = [];

    /** The moment the pinned clock answers in every case of both runs (`Y-m-d H:i:s`, the process time zone). */
    public string $pinnedMoment = '';

    /** The seed of the pinned id source; each case mints from `seed:caseId`. */
    public string $idSeed = '';

    /** @var int[] fixture conversation name → its id in the recording run (information only) */
    public array $recordedConversationIds = [];

    /** The outcomes of the cases, in script order. */
    public ToolCallComparisonCaseOutcomes $caseOutcomes;

    /** The base name of the definitions dump written next to the recording. */
    public string $definitionsDumpFileName = '';

    /** @var array The stored document of the Argus calls of the recording run ({@see ArgusTransportRecording::toStorageArray()}) */
    protected array $argusCalls = [];

    /** @var array The stored document of the calls the application's own recorders kept in the recording run */
    protected array $hostRecordedCalls = [];

    public function __construct()
    {
        parent::__construct();
        $this->caseOutcomes = ToolCallComparisonCaseOutcomes::newInstance();
    }

    /**
     * @throws RuntimeException when the text is no recording of {@see RECORDING_VERSION}
     */
    public static function fromJson(string $recordingJson, string $recordingName): static
    {
        $toolCallComparisonRecording = static::newInstance();
        $storedFields = json_decode($recordingJson, true);
        if (!is_array($storedFields) || ($storedFields[$toolCallComparisonRecording->findStoredKey('harness')] ?? null) !== static::HARNESS) {
            throw new RuntimeException("{$recordingName} is no recording of " . static::HARNESS);
        }
        $storedVersion = (int)($storedFields[$toolCallComparisonRecording->findStoredKey('version')] ?? 0);
        if ($storedVersion !== static::RECORDING_VERSION) {
            throw new RuntimeException("{$recordingName} is a version-{$storedVersion} recording; this build replays version " . static::RECORDING_VERSION . ' only — record it again');
        }
        $toolCallComparisonRecording->readStoredFields($storedFields);
        return $toolCallComparisonRecording;
    }

    /** The recording as the file holds it: the stored document, pretty-printed. */
    public function buildStoredJson(): string
    {
        $fieldValues = [
            'harness' => static::HARNESS,
            'version' => static::RECORDING_VERSION,
            'scriptName' => $this->scriptName,
            'scriptHash' => $this->scriptHash,
            'packageClass' => $this->packageClass,
            'comparisonAgentSpaceId' => $this->comparisonAgentSpaceId,
            'harnessIdsByPlaceholder' => $this->harnessIdsByPlaceholder,
            'pinnedMoment' => $this->pinnedMoment,
            'idSeed' => $this->idSeed,
            'recordedConversationIds' => $this->recordedConversationIds,
            'definitionsDumpFileName' => $this->definitionsDumpFileName,
            'caseOutcomes' => $this->caseOutcomes->toArray(),
            'argusCalls' => $this->argusCalls,
            'hostRecordedCalls' => $this->hostRecordedCalls,
        ];
        $storedFields = [];
        foreach (static::STORED_FIELDS_IN_ORDER as $fieldName) {
            $storedFields[$this->findStoredKey($fieldName)] = $fieldValues[$fieldName];
        }
        return (string)json_encode($storedFields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * The key each field is stored under, field → key. The default stores every field under its own name; an
     * application maps a field to the key its recordings carry.
     *
     * @return array<string, string>
     */
    public function getStoredKeyByField(): array
    {
        return array_combine(static::STORED_FIELDS_IN_ORDER, static::STORED_FIELDS_IN_ORDER);
    }

    /** The key `$fieldName` is stored under. */
    public function findStoredKey(string $fieldName): string
    {
        return $this->getStoredKeyByField()[$fieldName] ?? $fieldName;
    }

    /** Why a replay must not run this script against this recording, or null. */
    public function findReplayRefusal(ToolCallComparisonScript $toolCallComparisonScript): ?string
    {
        if ($toolCallComparisonScript->contentHash !== $this->scriptHash) {
            return "the script differs from the one the recording was made with ({$this->scriptName}) — record again, or replay with the recorded script";
        }
        return null;
    }

    /**
     * Keeps the Argus calls the recorder of the recording run kept.
     *
     * @param ArgusTransportRecording $argusTransportRecording the run's recorder
     * @return void
     */
    public function keepArgusCallsOf(ArgusTransportRecording $argusTransportRecording): void
    {
        $this->argusCalls = array_values($argusTransportRecording->toStorageArray());
    }

    /**
     * The player a replay answers the tools' Argus calls with: the recorded calls, each answered only inside a tool.
     *
     * @return ArgusTransportRecording
     */
    public function buildArgusToolCallPlayer(): ArgusTransportRecording
    {
        return ArgusTransportRecording::forPlayingToolCalls($this->argusCalls);
    }

    public function countArgusCalls(): int
    {
        return count($this->argusCalls);
    }

    /**
     * Keeps the calls the application's own recorder kept in the recording run.
     *
     * @param ToolCallComparisonHostRecordingInterface $hostRecording the run's recorder
     * @return void
     */
    public function keepHostRecordedCallsOf(ToolCallComparisonHostRecordingInterface $hostRecording): void
    {
        $this->hostRecordedCalls = array_values((array)json_decode($hostRecording->buildStoredCallsJson(), true));
    }

    /**
     * The calls the application's own recorder kept, as the JSON text of their stored document: what a replay's
     * recorder answers from.
     *
     * @return string
     */
    public function getStoredHostRecordedCallsJson(): string
    {
        return (string)json_encode($this->hostRecordedCalls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    public function countHostRecordedCalls(): int
    {
        return count($this->hostRecordedCalls);
    }

    /**
     * Reads the fields of a stored document under the keys of {@see getStoredKeyByField()}.
     *
     * @param array $storedFields the decoded recording
     */
    protected function readStoredFields(array $storedFields): void
    {
        $this->scriptName = (string)($storedFields[$this->findStoredKey('scriptName')] ?? '');
        $this->scriptHash = (string)($storedFields[$this->findStoredKey('scriptHash')] ?? '');
        $this->packageClass = (string)($storedFields[$this->findStoredKey('packageClass')] ?? '');
        $this->comparisonAgentSpaceId = (int)($storedFields[$this->findStoredKey('comparisonAgentSpaceId')] ?? 0);
        $this->harnessIdsByPlaceholder = array_map('strval', (array)($storedFields[$this->findStoredKey('harnessIdsByPlaceholder')] ?? []));
        $this->pinnedMoment = (string)($storedFields[$this->findStoredKey('pinnedMoment')] ?? '');
        $this->idSeed = (string)($storedFields[$this->findStoredKey('idSeed')] ?? '');
        $this->recordedConversationIds = array_map('intval', (array)($storedFields[$this->findStoredKey('recordedConversationIds')] ?? []));
        foreach ((array)($storedFields[$this->findStoredKey('caseOutcomes')] ?? []) as $storedCaseOutcome) {
            $caseOutcome = ToolCallComparisonCaseOutcome::fromArray((array)$storedCaseOutcome);
            $this->caseOutcomes->replace($caseOutcome);
        }
        $this->argusCalls = array_values((array)($storedFields[$this->findStoredKey('argusCalls')] ?? []));
        $this->hostRecordedCalls = array_values((array)($storedFields[$this->findStoredKey('hostRecordedCalls')] ?? []));
        $this->definitionsDumpFileName = (string)($storedFields[$this->findStoredKey('definitionsDumpFileName')] ?? '');
    }
}
