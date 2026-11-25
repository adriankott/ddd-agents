<?php

declare(strict_types=1);

namespace DDD\Testing\Agents\Surface;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The values in which two surface documents differ, in the order of the documents' paths.
 *
 * @implements IteratorAggregate<int, AgentSurfaceDifference>
 */
class AgentSurfaceDifferences implements IteratorAggregate, Countable
{
    /** @var AgentSurfaceDifference[] */
    protected array $elements = [];

    public function add(AgentSurfaceDifference $agentSurfaceDifference): void
    {
        $this->elements[] = $agentSurfaceDifference;
    }

    /**
     * @return AgentSurfaceDifference[]
     */
    public function getElements(): array
    {
        return $this->elements;
    }

    /**
     * The differences as a map a file can hold and a later run can be compared with: path => the compared document's
     * value, or the marker of a path the compared document does not have ({@see AgentSurfaceDocumentComparer::ABSENT}).
     *
     * @return array<string, mixed>
     */
    public function toComparedValuesByPath(): array
    {
        $comparedValuesByPath = [];
        foreach ($this->elements as $agentSurfaceDifference) {
            $comparedValuesByPath[$agentSurfaceDifference->path] = $agentSurfaceDifference->kind === AgentSurfaceDifference::KIND_REMOVED
                ? AgentSurfaceDocumentComparer::ABSENT
                : $agentSurfaceDifference->comparedValue;
        }
        return $comparedValuesByPath;
    }

    /**
     * @param int $maximumLines The lines listed before the rest is summed up in one line
     * @return string The differences for a failure message, one line each
     */
    public function describe(int $maximumLines = 80): string
    {
        $lines = [];
        foreach ($this->elements as $index => $agentSurfaceDifference) {
            if ($index >= $maximumLines) {
                $lines[] = sprintf('... and %d more', count($this->elements) - $maximumLines);
                break;
            }
            $lines[] = $agentSurfaceDifference->describe();
        }
        return implode("\n", $lines);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->elements);
    }

    public function count(): int
    {
        return count($this->elements);
    }
}
