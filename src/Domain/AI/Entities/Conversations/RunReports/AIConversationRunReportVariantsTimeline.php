<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\RunReports;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The variants TIMELINE of a run over the message rows that carry a variants vector in their request snapshot (index
 * order), and the compact initial → final delta.
 *
 * The first entry shows the initial vector (dimension `*`, from `—`); every later entry is ONE dimension whose token
 * changed against the previous snapshot. Tokens are compared with the evidence index `@N` stripped, so a re-anchored
 * evidence alone is no change. An entry carries the skill section ids that model call rendered (a prefix the caller
 * names is dropped from each id) and the tool of the next tool-call row. The delta lists only the dimensions whose
 * stripped token differs between the first and the last snapshot (`dimension from → to`, comma-joined); empty when
 * nothing changed or no row carries variants.
 *
 * Pure over the rows: a row is the array the metrics read returns (`i`, `t`, `tool`, `variants`, `skillSections`).
 *
 * @phpstan-consistent-constructor
 */
class AIConversationRunReportVariantsTimeline extends ValueObject
{
    /** @var string The evidence index of a token: `@` and digits */
    public const string EVIDENCE_INDEX_PATTERN = '/@\d+/';

    /** @var AIConversationRunReportVariantsChanges The entries, in message order */
    public AIConversationRunReportVariantsChanges $changes;

    /** @var string The dimensions that differ between the first and the last vector, evidence stripped */
    public string $delta = '';

    public function __construct()
    {
        parent::__construct();
        $this->changes = AIConversationRunReportVariantsChanges::newInstance();
    }

    /**
     * The timeline of the rows.
     *
     * @param AIConversationRunReportMessageRows $rows the message rows in index order
     * @param string $skillSectionIdPrefixToDrop a prefix dropped from the start of every skill section id; empty = none
     * @return static
     */
    public static function buildFromRows(AIConversationRunReportMessageRows $rows, string $skillSectionIdPrefixToDrop = ''): static
    {
        $variantsTimeline = static::newInstance();
        $initialVector = null;
        $previousVector = null;
        foreach ($rows->getElements() as $row) {
            $vector = static::decodeVariantsVector($row->getVariantsValue());
            if ($vector === null) {
                continue;
            }
            $messageIndex = $row->getMessageIndexOrZero();
            $skillSections = static::describeSkillSections($row->getSkillSectionsValue(), $skillSectionIdPrefixToDrop);
            $toolCalledAfter = $rows->getToolCalledAfterMessage($messageIndex);
            if ($previousVector === null) {
                $initialVector = $vector;
                $variantsTimeline->addInitialVector($vector, $messageIndex, $skillSections, $toolCalledAfter);
            } else {
                $variantsTimeline->addChangedDimensions($previousVector, $vector, $messageIndex, $skillSections, $toolCalledAfter);
            }
            $previousVector = $vector;
        }
        $variantsTimeline->delta = static::describeDelta($initialVector, $previousVector);
        return $variantsTimeline;
    }

    /**
     * The entry of the first vector: the whole vector as `dimension=token` pairs.
     *
     * @param array<string, string> $vector
     * @param int $messageIndex
     * @param string $skillSections
     * @param string $toolCalledAfter
     * @return void
     */
    protected function addInitialVector(array $vector, int $messageIndex, string $skillSections, string $toolCalledAfter): void
    {
        $variantsChange = AIConversationRunReportVariantsChange::forChange(
            $messageIndex,
            AIConversationRunReportVariantsChange::DIMENSION_INITIAL_VECTOR,
            AIConversationRunReportVariantsChange::NO_VALUE,
            static::describeVector($vector),
            $skillSections,
            $toolCalledAfter
        );
        $this->changes->add($variantsChange);
    }

    /**
     * One entry per dimension whose token changed (evidence stripped) or that one of the two vectors lacks.
     *
     * @param array<string, string> $previousVector
     * @param array<string, string> $vector
     * @param int $messageIndex
     * @param string $skillSections
     * @param string $toolCalledAfter
     * @return void
     */
    protected function addChangedDimensions(array $previousVector, array $vector, int $messageIndex, string $skillSections, string $toolCalledAfter): void
    {
        foreach (array_unique(array_merge(array_keys($previousVector), array_keys($vector))) as $dimension) {
            $fromToken = $previousVector[$dimension] ?? null;
            $toToken = $vector[$dimension] ?? null;
            if ($fromToken !== null && $toToken !== null && static::stripEvidenceIndex($fromToken) === static::stripEvidenceIndex($toToken)) {
                continue;
            }
            $variantsChange = AIConversationRunReportVariantsChange::forChange(
                $messageIndex,
                (string)$dimension,
                $fromToken ?? AIConversationRunReportVariantsChange::NO_VALUE,
                $toToken ?? AIConversationRunReportVariantsChange::NO_VALUE,
                $skillSections,
                $toolCalledAfter
            );
            $this->changes->add($variantsChange);
        }
    }

    /**
     * The dimensions whose stripped token differs between the first and the last vector.
     *
     * @param array<string, string>|null $initialVector
     * @param array<string, string>|null $finalVector
     * @return string `dimension from → to`, comma-joined; empty without a change or a vector
     */
    protected static function describeDelta(?array $initialVector, ?array $finalVector): string
    {
        if ($initialVector === null || $finalVector === null) {
            return '';
        }
        $deltas = [];
        foreach (array_unique(array_merge(array_keys($initialVector), array_keys($finalVector))) as $dimension) {
            $fromToken = static::stripEvidenceIndex($initialVector[$dimension] ?? AIConversationRunReportVariantsChange::NO_VALUE);
            $toToken = static::stripEvidenceIndex($finalVector[$dimension] ?? AIConversationRunReportVariantsChange::NO_VALUE);
            if ($fromToken !== $toToken) {
                $deltas[] = $dimension . ' ' . $fromToken . ' → ' . $toToken;
            }
        }
        return implode(', ', $deltas);
    }

    /**
     * A vector as `dimension=token` pairs, space-joined.
     *
     * @param array<string, string> $vector
     * @return string
     */
    public static function describeVector(array $vector): string
    {
        return implode(' ', array_map(static fn (string $dimension, string $token): string => $dimension . '=' . $token, array_keys($vector), $vector));
    }

    /**
     * A row's variants vector (`dimension => token`), or null when the row carries none.
     *
     * @param mixed $variantsJson the row's `variants` column (JSON text)
     * @return array<string, string>|null
     */
    public static function decodeVariantsVector(mixed $variantsJson): ?array
    {
        if (!is_string($variantsJson) || $variantsJson === '' || $variantsJson === 'null') {
            return null;
        }
        $decodedVector = json_decode($variantsJson, true);
        if (!is_array($decodedVector) || $decodedVector === []) {
            return null;
        }
        $vector = [];
        foreach ($decodedVector as $dimension => $token) {
            $vector[(string)$dimension] = (string)$token;
        }
        return $vector;
    }

    /** A token without its evidence index `@N` — a re-anchored evidence alone is no change. */
    public static function stripEvidenceIndex(string $token): string
    {
        return (string)preg_replace(static::EVIDENCE_INDEX_PATTERN, '', $token);
    }

    /**
     * A row's rendered skill section ids, comma-joined, the prefix dropped from the start of each.
     *
     * @param mixed $skillSectionsJson the row's `skillSections` column (JSON text)
     * @param string $skillSectionIdPrefixToDrop empty = no prefix dropped
     * @return string
     */
    public static function describeSkillSections(mixed $skillSectionsJson, string $skillSectionIdPrefixToDrop = ''): string
    {
        if (!is_string($skillSectionsJson) || $skillSectionsJson === '' || $skillSectionsJson === 'null') {
            return '';
        }
        $decodedSectionIds = json_decode($skillSectionsJson, true);
        if (!is_array($decodedSectionIds)) {
            return '';
        }
        $sectionIds = [];
        foreach ($decodedSectionIds as $sectionId) {
            $sectionIds[] = static::dropPrefix((string)$sectionId, $skillSectionIdPrefixToDrop);
        }
        return implode(', ', $sectionIds);
    }

    /**
     * @param string $sectionId
     * @param string $prefix empty = nothing dropped
     * @return string the id without the prefix at its start
     */
    protected static function dropPrefix(string $sectionId, string $prefix): string
    {
        return $prefix !== '' && str_starts_with($sectionId, $prefix) ? substr($sectionId, strlen($prefix)) : $sectionId;
    }
}
