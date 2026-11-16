<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

/**
 * A recorder of the application's own that a comparison run installs beside the Argus recording: it keeps the calls
 * the tools make to a back end of the application (record) or answers them from a recording (replay), labels them with
 * the call that runs, hands the requests of one call to its case outcome, and names the recorded calls a replay never
 * asked for. Its calls travel as the JSON text of their stored document, so no structure of the application crosses
 * the package's classes.
 *
 * Built and installed by the application's session ({@see ToolCallComparisonSession::buildHostRecording()}); a
 * replay's recorder is built from {@see ToolCallComparisonRecording::getStoredHostRecordedCallsJson()}.
 */
interface ToolCallComparisonHostRecordingInterface
{
    /**
     * Puts the label of the call that runs now on every call the recorder keeps or answers from now on.
     *
     * @param string $toolCallLabel a case id, a harness step id or the conversation setup's label
     * @return void
     */
    public function labelCalls(string $toolCallLabel): void;

    /**
     * The calls the recorder kept in this run, as the JSON text of their stored document (a JSON list); `[]` when it
     * kept none or plays a recording.
     *
     * @return string
     */
    public function buildStoredCallsJson(): string;

    /**
     * The requests of the call `$toolCallLabel`, as two runs compare them, as the JSON text of a JSON list.
     *
     * @param string $toolCallLabel
     * @return string
     */
    public function buildStoredRequestsOfCallJson(string $toolCallLabel): string;

    /**
     * The recorded calls a replay never asked for whose label is none of `$harnessCallLabels`, one line each; none
     * while recording.
     *
     * @param string[] $harnessCallLabels the labels of the calls that are not compared
     * @return string[]
     */
    public function findUnusedCallLines(array $harnessCallLabels): array;

    /**
     * Removes the recorder from the process (called in a `finally`).
     *
     * @return void
     */
    public function uninstall(): void;
}
