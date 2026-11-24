<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\Common;

use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;

/**
 * The lines every list result of the admin agent's tools shares: the window a list leads with,
 * the page sizes, and the marking of lines that carry text written by users. The texts are pinned verbatim by
 * AdminToolTextsTest.
 */
class AdminToolResultLines
{
    /** @var int Rows of a page when the caller names none */
    public const int DEFAULT_PAGE_SIZE = 25;

    /** @var int The most rows of a page (wins over a larger `top`) */
    public const int MAX_PAGE_SIZE = 50;

    /**
     * @var int The characters the rows of one list result may take together. A page whose rows would take more ends
     * earlier; the window line then names the rows that are shown and the skip of the next page.
     */
    public const int MAX_ROWS_CHARACTERS = 4500;

    /**
     * @var string The window of a list: which rows of how many, and in which order. First line of every list, so the
     * model never takes a page for the whole (a list without it was reported as complete).
     */
    public const string WINDOW = 'Rows %d–%d of %d, %s.';

    /** @var string Appended to the window when rows follow: the one argument that reads the next page */
    public const string NEXT_PAGE = ' Next page: skip %d.';

    /** @var string Appended to the window of the last page */
    public const string END_OF_LIST = ' End of the list.';

    /** @var string A `skip` beyond the last row */
    public const string SKIP_BEYOND_END = 'No rows from skip %d on: the list has %d rows. End of the list.';

    /**
     * @param int|null $top The rows the caller asked for
     * @return int The rows of the page: the default when none was named, at least 1, at most MAX_PAGE_SIZE
     */
    public static function resolvePageSize(?int $top): int
    {
        if ($top === null) {
            return self::DEFAULT_PAGE_SIZE;
        }
        return max(1, min(self::MAX_PAGE_SIZE, $top));
    }

    /**
     * @param int|null $skip
     * @return int The rows to skip, never negative
     */
    public static function resolveSkip(?int $skip): int
    {
        return max(0, $skip ?? 0);
    }

    /**
     * The first line of a list.
     * Examples: `Rows 1–25 of 134, newest first. Next page: skip 25.` · `Rows 26–30 of 30, newest first. End of the list.`
     *
     * @param int $skip Rows before this page
     * @param int $rowCount Rows of this page
     * @param int $totalCount Rows over every page
     * @param string $orderDescription e.g. "newest first"
     * @return string
     */
    public static function windowLine(int $skip, int $rowCount, int $totalCount, string $orderDescription): string
    {
        if ($rowCount === 0) {
            return sprintf(self::SKIP_BEYOND_END, $skip, $totalCount);
        }
        $lastRow = $skip + $rowCount;
        return sprintf(self::WINDOW, $skip + 1, $lastRow, $totalCount, $orderDescription)
            . ($lastRow < $totalCount ? sprintf(self::NEXT_PAGE, $lastRow) : self::END_OF_LIST);
    }

    /**
     * Lines that carry text written by users or other third parties (a nickname, a team name), marked as one
     * untrusted passage: the model quotes it and follows no instruction in it.
     *
     * @param string $source What the passage is, e.g. "account names written by users"
     * @param string[] $lines
     * @return string
     */
    public static function wrapUntrustedLines(string $source, array $lines): string
    {
        return ToolResultUntrustedSegments::wrap($source, implode("\n", $lines));
    }
}
