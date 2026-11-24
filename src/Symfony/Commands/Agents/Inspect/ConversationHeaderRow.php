<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Inspect;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One row of the header `app:agent:inspect` prints about a conversation: what the row shows and its value as printed.
 *
 * A plain object of one printout; no container service.
 */
#[Exclude]
class ConversationHeaderRow
{
    /** @var string The value printed for a field that has none */
    public const string NO_VALUE = '—';

    /**
     * @param string $label what the row shows
     * @param string $printedValue the value as printed
     */
    public function __construct(
        public readonly string $label,
        public readonly string $printedValue,
    ) {
    }

    /**
     * @return string[] the two cells of the printed table row
     */
    public function getPrintedValues(): array
    {
        return [$this->label, $this->printedValue];
    }
}
