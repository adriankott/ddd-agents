<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetOrigin;
use DDD\Domain\Base\Entities\Comparisons\RecordedSequence;
use DDD\Domain\Base\Entities\Comparisons\RecordedSequenceComparison;
use DDD\Domain\Base\Entities\MessageHandlers\ComparableMessageDispatches;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\Doctrine\Audit\DatabaseWriteStatementShapes;

/**
 * What one scripted tool call produced, as two runs compare it: every field is the raw output with only the database
 * ids replaced ({@see ToolCallComparisonIdPlaceholders}). Recorded once, rebuilt by the replay, compared field by field
 * ({@see buildDifferencesFrom()}). Pure.
 *
 * - {@see $resultContent}: the tool result exactly as the loop hands it to the model (the executor's JSON of the MCP
 *   response: the text, the structured content with the loop-control fields, the error frame) with {@see $isError} and
 *   {@see $refusalKind}; an exception the executor did not turn into a result (tool not found, a lost run claim) is
 *   {@see $executionError};
 * - the rows the call appended to each harness conversation, in order (the `signalSource` of a platform-written widget
 *   row is left out);
 * - every INSERT / UPDATE / DELETE the call issued, in order, with columns and values;
 * - every Messenger message the call dispatched (class + body), in order;
 * - the requests the application's own recorders kept for the call (none by default), the Argus requests the tool sent
 *   (endpoint and body, normalised like every other field) and their number ({@see $argusCallCount}).
 *
 * The structures are the stored document of the case (decoded JSON, kept protected); a reader asks for their counts.
 * The stored key of every field comes from {@see getStoredKeyByField()}: an application whose recordings carry other
 * key names maps them there, so its recordings stay readable and byte-identical.
 *
 * @phpstan-consistent-constructor
 */
class ToolCallComparisonCaseOutcome extends ValueObject
{
    /** @var string[] The fields of the stored document, in their stored order */
    public const array STORED_FIELDS_IN_ORDER = [
        'caseId',
        'toolName',
        'resultContent',
        'isError',
        'refusalKind',
        'executionError',
        'appendedRowsByConversation',
        'writeStatements',
        'dispatches',
        'hostRequests',
        'argusRequests',
        'argusCallCount',
        'statementAuditGap',
    ];

    /** @var string The label of a request of the application's own recorders in a difference line */
    public const string HOST_REQUEST_LABEL = 'host request';

    public string $caseId = '';

    public string $toolName = '';

    public string $resultContent = '';

    public bool $isError = false;

    public ?string $refusalKind = null;

    public ?string $executionError = null;

    public int $argusCallCount = 0;

    /** Why the statement audit may have missed statements (an interrupted connection, a statement it could not keep); null = complete. */
    public ?string $statementAuditGap = null;

    /** @var array The stored document of the rows the call appended, conversation name → its rows in order */
    protected array $appendedRowsByConversation = [];

    /** @var array The stored document of the write statements, in order */
    protected array $writeStatements = [];

    /** @var array The stored document of the dispatched messages, in order */
    protected array $dispatches = [];

    /** @var array The stored document of the requests the application's own recorders kept for the call, in order */
    protected array $hostRequests = [];

    /** @var array The stored document of the Argus requests the call sent, in order: endpoint and body */
    protected array $argusRequests = [];

    /** The outcome of one case before anything is kept. */
    public static function forCase(string $caseId, string $toolName): static
    {
        $toolCallComparisonCaseOutcome = static::newInstance();
        $toolCallComparisonCaseOutcome->caseId = $caseId;
        $toolCallComparisonCaseOutcome->toolName = $toolName;
        return $toolCallComparisonCaseOutcome;
    }

    /**
     * The outcome from its stored document (as {@see toArray()} writes it, under the keys of {@see getStoredKeyByField()}).
     *
     * @param array $storedFields the stored document of one case
     */
    public static function fromArray(array $storedFields): static
    {
        $toolCallComparisonCaseOutcome = static::newInstance();
        $toolCallComparisonCaseOutcome->readStoredFields($storedFields);
        return $toolCallComparisonCaseOutcome;
    }

    /**
     * The stored document of the case: every field of {@see STORED_FIELDS_IN_ORDER}, in that order, under its stored key.
     *
     * @return array
     */
    public function toArray(): array
    {
        $fieldValues = [
            'caseId' => $this->caseId,
            'toolName' => $this->toolName,
            'resultContent' => $this->resultContent,
            'isError' => $this->isError,
            'refusalKind' => $this->refusalKind,
            'executionError' => $this->executionError,
            'appendedRowsByConversation' => $this->appendedRowsByConversation,
            'writeStatements' => $this->writeStatements,
            'dispatches' => $this->dispatches,
            'hostRequests' => $this->hostRequests,
            'argusRequests' => $this->argusRequests,
            'argusCallCount' => $this->argusCallCount,
            'statementAuditGap' => $this->statementAuditGap,
        ];
        $storedFields = [];
        foreach (static::STORED_FIELDS_IN_ORDER as $fieldName) {
            $storedFields[$this->findStoredKey($fieldName)] = $fieldValues[$fieldName];
        }
        return $storedFields;
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

    /** The key {@see ToolCallComparisonCaseOutcomes} holds the outcome of `$caseId` under. */
    public static function buildUniqueKeyOfCase(string $caseId): string
    {
        return self::class . '_' . $caseId;
    }

    public function uniqueKey(): string
    {
        return static::buildUniqueKeyOfCase($this->caseId);
    }

    /**
     * Keeps the rows the call appended to one harness conversation: the rows after `$newestRowIndexBefore`, in order,
     * as compared ({@see buildComparableRow()}), the ids normalised. A conversation without a new row keeps nothing.
     *
     * @param AIConversationMessages $conversationMessages the conversation's rows after the call
     * @param int $newestRowIndexBefore the index of its newest row before the call
     * @param ToolCallComparisonIdPlaceholders $idPlaceholders the run's normalisation
     * @return void
     */
    public function keepAppendedRowsOf(string $conversationName, AIConversationMessages $conversationMessages, int $newestRowIndexBefore, ToolCallComparisonIdPlaceholders $idPlaceholders): void
    {
        $appendedRows = [];
        foreach ($conversationMessages->getElements() as $message) {
            if ((int)($message->indexInConversation ?? -1) <= $newestRowIndexBefore) {
                continue;
            }
            $appendedRows[(int)$message->indexInConversation] = static::buildComparableRow(
                $message->messageType ?? null,
                $message->role ?? null,
                $message->authorType ?? null,
                $message->signalSource ?? null,
                $message->message ?? null
            );
        }
        if ($appendedRows === []) {
            return;
        }
        ksort($appendedRows);
        $this->appendedRowsByConversation[$conversationName] = $idPlaceholders->normalizeStructure(array_values($appendedRows));
    }

    /**
     * Keeps the write statements of the call, the ids normalised.
     *
     * @param DatabaseWriteStatementShapes $databaseWriteStatementShapes the statements as the audit compares them
     * @param ToolCallComparisonIdPlaceholders $idPlaceholders the run's normalisation
     * @return void
     */
    public function keepWriteStatementsOf(DatabaseWriteStatementShapes $databaseWriteStatementShapes, ToolCallComparisonIdPlaceholders $idPlaceholders): void
    {
        $this->writeStatements = array_values($idPlaceholders->normalizeStructure($databaseWriteStatementShapes->toArray()));
    }

    /**
     * Keeps the messages the call dispatched, the ids normalised.
     *
     * @param ComparableMessageDispatches $comparableMessageDispatches the dispatches as the capture compares them
     * @param ToolCallComparisonIdPlaceholders $idPlaceholders the run's normalisation
     * @return void
     */
    public function keepDispatchesOf(ComparableMessageDispatches $comparableMessageDispatches, ToolCallComparisonIdPlaceholders $idPlaceholders): void
    {
        $this->dispatches = array_values($idPlaceholders->normalizeStructure($comparableMessageDispatches->toArray()));
    }

    /**
     * Keeps the requests the application's own recorder kept for this case, the ids normalised; none without a recorder.
     *
     * @param ToolCallComparisonHostRecordingInterface|null $hostRecording the run's recorder of the application
     * @param ToolCallComparisonIdPlaceholders $idPlaceholders the run's normalisation
     * @return void
     */
    public function keepHostRequestsOf(?ToolCallComparisonHostRecordingInterface $hostRecording, ToolCallComparisonIdPlaceholders $idPlaceholders): void
    {
        $storedRequests = $hostRecording !== null ? (array)json_decode($hostRecording->buildStoredRequestsOfCallJson($this->caseId), true) : [];
        $this->hostRequests = array_values($idPlaceholders->normalizeStructure($storedRequests));
    }

    /**
     * Keeps the Argus requests the call sent, the ids normalised.
     *
     * @param ToolCallComparisonArgusRequests $toolCallComparisonArgusRequests the requests in the order they were sent
     * @param ToolCallComparisonIdPlaceholders $idPlaceholders the run's normalisation
     * @return void
     */
    public function keepArgusRequestsOf(ToolCallComparisonArgusRequests $toolCallComparisonArgusRequests, ToolCallComparisonIdPlaceholders $idPlaceholders): void
    {
        $this->argusRequests = array_values($idPlaceholders->normalizeStructure($toolCallComparisonArgusRequests->toArray()));
    }

    /** How many rows the call appended to the harness conversations together. */
    public function countAppendedRows(): int
    {
        $appendedRowCount = 0;
        foreach ($this->appendedRowsByConversation as $appendedRows) {
            $appendedRowCount += count((array)$appendedRows);
        }
        return $appendedRowCount;
    }

    public function countWriteStatements(): int
    {
        return count($this->writeStatements);
    }

    public function countDispatches(): int
    {
        return count($this->dispatches);
    }

    public function countHostRequests(): int
    {
        return count($this->hostRequests);
    }

    public function countArgusRequests(): int
    {
        return count($this->argusRequests);
    }

    /**
     * One appended conversation row as it is compared: type, role, author, label and text; the label of a
     * platform-written widget row is left out.
     *
     * @return array the stored document of the row
     */
    protected static function buildComparableRow(?string $messageType, ?string $role, ?string $authorType, ?string $signalSource, ?string $messageText): array
    {
        $comparableRow = ['messageType' => $messageType, 'role' => $role, 'authorType' => $authorType];
        if ($signalSource !== null && !str_starts_with($signalSource, ConversationWidgetOrigin::PLATFORM_PREFIX)) {
            $comparableRow['signalSource'] = $signalSource;
        }
        $comparableRow['message'] = $messageText;
        return $comparableRow;
    }

    /**
     * The differences between the recorded outcome ($this) and the replayed one, one line each; empty = identical.
     *
     * @return string[]
     */
    public function buildDifferencesFrom(self $replayedOutcome): array
    {
        $differences = [];
        foreach ([
            'result' => [$this->resultContent, $replayedOutcome->resultContent],
            'isError' => [$this->isError, $replayedOutcome->isError],
            'refusalKind' => [$this->refusalKind, $replayedOutcome->refusalKind],
            'executionError' => [$this->executionError, $replayedOutcome->executionError],
            'Argus call count' => [$this->argusCallCount, $replayedOutcome->argusCallCount],
        ] as $fieldLabel => [$recordedValue, $replayedValue]) {
            $differences = array_merge($differences, $this->buildSequenceDifferences($fieldLabel, [$recordedValue], [$replayedValue]));
        }
        $conversationNames = array_unique(array_merge(array_keys($this->appendedRowsByConversation), array_keys($replayedOutcome->appendedRowsByConversation)));
        sort($conversationNames);
        foreach ($conversationNames as $conversationName) {
            $differences = array_merge($differences, $this->buildSequenceDifferences(
                "row of {$conversationName}",
                (array)($this->appendedRowsByConversation[$conversationName] ?? []),
                (array)($replayedOutcome->appendedRowsByConversation[$conversationName] ?? [])
            ));
        }
        $differences = array_merge(
            $differences,
            $this->buildSequenceDifferences('statement', $this->writeStatements, $replayedOutcome->writeStatements),
            $this->buildSequenceDifferences('dispatch', $this->dispatches, $replayedOutcome->dispatches),
            $this->buildSequenceDifferences($this->getHostRequestLabel(), $this->hostRequests, $replayedOutcome->hostRequests),
            $this->buildSequenceDifferences('Argus request', $this->argusRequests, $replayedOutcome->argusRequests)
        );
        foreach (['recorded' => $this->statementAuditGap, 'replayed' => $replayedOutcome->statementAuditGap] as $runName => $statementAuditGap) {
            if ($statementAuditGap !== null) {
                $differences[] = "statement audit of the {$runName} run is incomplete: {$statementAuditGap}";
            }
        }
        return $differences;
    }

    /** The label of a request of the application's own recorders in a difference line. */
    public function getHostRequestLabel(): string
    {
        return static::HOST_REQUEST_LABEL;
    }

    /**
     * The differences of two stored sequences under one label ({@see RecordedSequenceComparison}).
     *
     * @param array $recordedDocuments the recorded stored documents, in order
     * @param array $replayedDocuments the replayed stored documents, in order
     * @return string[]
     */
    protected function buildSequenceDifferences(string $label, array $recordedDocuments, array $replayedDocuments): array
    {
        return RecordedSequenceComparison::buildDifferences(
            $label,
            RecordedSequence::fromStoredDocuments($recordedDocuments),
            RecordedSequence::fromStoredDocuments($replayedDocuments)
        );
    }

    /**
     * Reads the fields of a stored document under the keys of {@see getStoredKeyByField()}.
     *
     * @param array $storedFields the stored document of one case
     */
    protected function readStoredFields(array $storedFields): void
    {
        $this->caseId = (string)($storedFields[$this->findStoredKey('caseId')] ?? '');
        $this->toolName = (string)($storedFields[$this->findStoredKey('toolName')] ?? '');
        $this->resultContent = (string)($storedFields[$this->findStoredKey('resultContent')] ?? '');
        $this->isError = (bool)($storedFields[$this->findStoredKey('isError')] ?? false);
        $this->refusalKind = isset($storedFields[$this->findStoredKey('refusalKind')]) ? (string)$storedFields[$this->findStoredKey('refusalKind')] : null;
        $this->executionError = isset($storedFields[$this->findStoredKey('executionError')]) ? (string)$storedFields[$this->findStoredKey('executionError')] : null;
        $this->appendedRowsByConversation = (array)($storedFields[$this->findStoredKey('appendedRowsByConversation')] ?? []);
        $this->writeStatements = array_values((array)($storedFields[$this->findStoredKey('writeStatements')] ?? []));
        $this->dispatches = array_values((array)($storedFields[$this->findStoredKey('dispatches')] ?? []));
        $this->hostRequests = array_values((array)($storedFields[$this->findStoredKey('hostRequests')] ?? []));
        $this->argusRequests = array_values((array)($storedFields[$this->findStoredKey('argusRequests')] ?? []));
        $this->argusCallCount = (int)($storedFields[$this->findStoredKey('argusCallCount')] ?? 0);
        $this->statementAuditGap = isset($storedFields[$this->findStoredKey('statementAuditGap')]) ? (string)$storedFields[$this->findStoredKey('statementAuditGap')] : null;
    }
}
