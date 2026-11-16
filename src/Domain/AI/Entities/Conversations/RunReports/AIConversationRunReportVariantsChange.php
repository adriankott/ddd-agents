<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\RunReports;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * ONE entry of the variants timeline of a run ({@see AIConversationRunReportVariantsTimeline}): the initial vector
 * (dimension {@see self::DIMENSION_INITIAL_VECTOR}, from {@see self::NO_VALUE}) or one dimension whose token changed
 * against the previous snapshot, with the skill sections that model call rendered and the tool called next.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationRunReportVariantsChange extends ValueObject
{
    /** @var string The dimension of the entry that shows the initial vector */
    public const string DIMENSION_INITIAL_VECTOR = '*';

    /** @var string The token of a dimension the vector does not carry */
    public const string NO_VALUE = '—';

    /** @var int The index of the message whose request snapshot carried the vector */
    public int $messageIndex = 0;

    /** @var string The dimension that changed, or {@see self::DIMENSION_INITIAL_VECTOR} */
    public string $dimension = '';

    /** @var string The token before the change */
    public string $fromToken = '';

    /** @var string The token after the change (the whole vector for the initial entry) */
    public string $toToken = '';

    /** @var string The skill section ids that model call rendered, comma-joined */
    public string $skillSections = '';

    /** @var string The tool of the first tool call after the message; empty when none followed */
    public string $toolCalledAfter = '';

    /**
     * @param int $messageIndex
     * @param string $dimension
     * @param string $fromToken
     * @param string $toToken
     * @param string $skillSections
     * @param string $toolCalledAfter
     * @return static
     */
    public static function forChange(int $messageIndex, string $dimension, string $fromToken, string $toToken, string $skillSections, string $toolCalledAfter): static
    {
        $variantsChange = static::newInstance();
        $variantsChange->messageIndex = $messageIndex;
        $variantsChange->dimension = $dimension;
        $variantsChange->fromToken = $fromToken;
        $variantsChange->toToken = $toToken;
        $variantsChange->skillSections = $skillSections;
        $variantsChange->toolCalledAfter = $toolCalledAfter;
        return $variantsChange;
    }

    /**
     * The entry under the keys the run report prints it with.
     *
     * @return array{at: int, dimension: string, from: string, to: string, sections: string, toolAfter: string}
     */
    public function toArray(): array
    {
        return [
            'at' => $this->messageIndex,
            'dimension' => $this->dimension,
            'from' => $this->fromToken,
            'to' => $this->toToken,
            'sections' => $this->skillSections,
            'toolAfter' => $this->toolCalledAfter,
        ];
    }
}
