<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonHostRecordingInterface;

/**
 * An application's recorder as a test hands it in: the calls it kept and the requests per label are JSON texts the
 * test writes; it records the labels it was given, whether it was removed, and names as unused every kept call whose
 * label is not a harness label.
 */
class ToolCallComparisonHostRecordingDouble implements ToolCallComparisonHostRecordingInterface
{
    /** @var string[] The labels the run put on the recorder, in order */
    public array $labels = [];

    public bool $uninstalled = false;

    /**
     * @param string $storedCallsJson the JSON list of the kept calls; each `{"label": …, "request": …}`
     */
    public function __construct(public string $storedCallsJson = '[]')
    {
    }

    public function labelCalls(string $toolCallLabel): void
    {
        $this->labels[] = $toolCallLabel;
    }

    public function buildStoredCallsJson(): string
    {
        return $this->storedCallsJson;
    }

    public function buildStoredRequestsOfCallJson(string $toolCallLabel): string
    {
        $storedRequests = [];
        foreach ((array)json_decode($this->storedCallsJson, true) as $storedCall) {
            if (($storedCall['label'] ?? null) === $toolCallLabel) {
                $storedRequests[] = $storedCall['request'] ?? null;
            }
        }
        return (string)json_encode($storedRequests);
    }

    public function findUnusedCallLines(array $harnessCallLabels): array
    {
        $unusedCallLines = [];
        foreach ((array)json_decode($this->storedCallsJson, true) as $position => $storedCall) {
            if (!in_array($storedCall['label'] ?? null, $harnessCallLabels, true)) {
                $unusedCallLines[] = "unused recorded back-end call #{$position} " . ($storedCall['label'] ?? '-');
            }
        }
        return $unusedCallLines;
    }

    public function uninstall(): void
    {
        $this->uninstalled = true;
    }
}
