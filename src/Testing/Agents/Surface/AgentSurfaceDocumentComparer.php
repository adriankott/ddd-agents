<?php

declare(strict_types=1);

namespace DDD\Testing\Agents\Surface;

/**
 * Compares two surface documents ({@see AgentSurfaceDocumentBuilder}) value by value. A document is a tree of maps
 * whose leaves are scalars, null or lists; the comparison walks the maps and names every leaf that is added, removed
 * or changed by its path. A list is one value: two lists differ as a whole.
 *
 * A top-level part of a document is either a map of entries (`skills`, `packages`, …) or a list (`skillOrder`). Where
 * two documents are set against each other entry by entry ({@see buildDifferencesToEntriesOf()},
 * {@see restrictToEntriesOf()}, {@see withoutEntriesOf()}), a list part belongs to its own document only.
 *
 * No kernel, no container: the class works on arrays.
 */
class AgentSurfaceDocumentComparer
{
    /** The joint between the keys of a path */
    public const string PATH_SEPARATOR = ' → ';

    /** In a map of compared values by path: the compared document does not have the path */
    public const string ABSENT = '(absent)';

    /**
     * @param array<string, mixed> $referenceDocument
     * @param array<string, mixed> $comparedDocument
     */
    public function compare(array $referenceDocument, array $comparedDocument): AgentSurfaceDifferences
    {
        $agentSurfaceDifferences = new AgentSurfaceDifferences();
        $referenceValuesByPath = $this->flatten($referenceDocument);
        $comparedValuesByPath = $this->flatten($comparedDocument);
        foreach ($referenceValuesByPath as $path => $referenceValue) {
            if (!array_key_exists($path, $comparedValuesByPath)) {
                if ($referenceValue === [] && $this->hasValueBelowPath($comparedValuesByPath, $path)) {
                    continue;
                }
                $agentSurfaceDifference = new AgentSurfaceDifference(AgentSurfaceDifference::KIND_REMOVED, $path, $referenceValue, null);
                $agentSurfaceDifferences->add($agentSurfaceDifference);
                continue;
            }
            if ($comparedValuesByPath[$path] !== $referenceValue) {
                $agentSurfaceDifference = new AgentSurfaceDifference(AgentSurfaceDifference::KIND_CHANGED, $path, $referenceValue, $comparedValuesByPath[$path]);
                $agentSurfaceDifferences->add($agentSurfaceDifference);
            }
        }
        foreach ($comparedValuesByPath as $path => $comparedValue) {
            if (!array_key_exists($path, $referenceValuesByPath)) {
                if ($comparedValue === [] && $this->hasValueBelowPath($referenceValuesByPath, $path)) {
                    continue;
                }
                $agentSurfaceDifference = new AgentSurfaceDifference(AgentSurfaceDifference::KIND_ADDED, $path, null, $comparedValue);
                $agentSurfaceDifferences->add($agentSurfaceDifference);
            }
        }
        return $agentSurfaceDifferences;
    }

    /**
     * Whether a document has a value below a path. An empty map on one side and a filled map on the other are no
     * difference of their own: the entries of the filled one are reported.
     *
     * @param array<string, mixed> $valuesByPath
     */
    public function hasValueBelowPath(array $valuesByPath, string $path): bool
    {
        $pathPrefix = $path . static::PATH_SEPARATOR;
        foreach (array_keys($valuesByPath) as $otherPath) {
            if (str_starts_with((string)$otherPath, $pathPrefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Where a document differs from a reference document in the entries the reference has: path => the document's
     * value, or {@see ABSENT} for a path the document lacks. An entry the document lacks as a whole (a skill the
     * application does not offer, a package it does not bind) is one difference by the entry's path
     * (`skills → Agent.UI`), not one per value of it. Entries only the document has are no part of the result
     * ({@see withoutEntriesOf()}).
     *
     * @param array<string, mixed> $referenceDocument
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function buildDifferencesToEntriesOf(array $referenceDocument, array $document): array
    {
        $differencesByPath = [];
        foreach ($referenceDocument as $partName => $referencePart) {
            if (!$this->isPartOfEntries($referencePart)) {
                unset($referenceDocument[$partName]);
                continue;
            }
            foreach (array_keys($referencePart) as $entryName) {
                if (!is_array($document[$partName] ?? null) || !array_key_exists($entryName, $document[$partName])) {
                    $differencesByPath[$partName . static::PATH_SEPARATOR . $entryName] = static::ABSENT;
                    unset($referenceDocument[$partName][$entryName]);
                }
            }
        }
        $differencesOfSharedEntries = $this->compare($referenceDocument, $this->restrictToEntriesOf($document, $referenceDocument));
        foreach ($differencesOfSharedEntries->toComparedValuesByPath() as $path => $comparedValue) {
            $differencesByPath[$path] = $comparedValue;
        }
        return $differencesByPath;
    }

    /**
     * The part of a document under the entries another document has (its top-level parts and their keys): what the
     * document says about the entries the reference document knows. An entry the document lacks stays out; the
     * comparison then reports it as removed.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $referenceDocument
     * @return array<string, mixed>
     */
    public function restrictToEntriesOf(array $document, array $referenceDocument): array
    {
        $restrictedDocument = [];
        foreach ($referenceDocument as $partName => $referencePart) {
            if (!$this->isPartOfEntries($referencePart) || !$this->isPartOfEntries($document[$partName] ?? null)) {
                continue;
            }
            $restrictedDocument[$partName] = [];
            foreach (array_keys($referencePart) as $entryName) {
                if (array_key_exists($entryName, $document[$partName])) {
                    $restrictedDocument[$partName][$entryName] = $document[$partName][$entryName];
                }
            }
        }
        return $restrictedDocument;
    }

    /**
     * The part of a document outside the entries another document has: the application's own skills, packages,
     * purposes and bundles, without the ones the reference document knows. The counterpart of
     * {@see restrictToEntriesOf()}; together the two hold every entry of the document once.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $referenceDocument
     * @return array<string, mixed>
     */
    public function withoutEntriesOf(array $document, array $referenceDocument): array
    {
        $remainingDocument = [];
        foreach ($document as $partName => $part) {
            if (!$this->isPartOfEntries($part) || !$this->isPartOfEntries($referenceDocument[$partName] ?? null)) {
                $remainingDocument[$partName] = $part;
                continue;
            }
            $remainingDocument[$partName] = [];
            foreach ($part as $entryName => $entry) {
                if (!array_key_exists($entryName, $referenceDocument[$partName])) {
                    $remainingDocument[$partName][$entryName] = $entry;
                }
            }
        }
        return $remainingDocument;
    }

    /**
     * Whether a top-level part of a document is a map of entries (an empty part counts as one), not a list.
     */
    public function isPartOfEntries(mixed $part): bool
    {
        return is_array($part) && ($part === [] || !array_is_list($part));
    }

    /**
     * Every leaf of a document by its path. A map (an array that is not a list) is walked; a list, a scalar, null and
     * an empty array are leaves.
     *
     * @param array<string|int, mixed> $document
     * @return array<string, mixed>
     */
    public function flatten(array $document, string $pathPrefix = ''): array
    {
        $valuesByPath = [];
        foreach ($document as $key => $value) {
            $path = $pathPrefix === '' ? (string)$key : $pathPrefix . static::PATH_SEPARATOR . $key;
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                foreach ($this->flatten($value, $path) as $nestedPath => $nestedValue) {
                    $valuesByPath[$nestedPath] = $nestedValue;
                }
                continue;
            }
            $valuesByPath[$path] = $value;
        }
        return $valuesByPath;
    }
}
