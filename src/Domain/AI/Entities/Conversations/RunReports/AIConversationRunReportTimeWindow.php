<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\RunReports;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The time window of a run in which a usage log is read for its model calls: from the earliest `created` of its
 * message rows to {@see self::TRAILING_SECONDS} after the latest one. Both ends are `Y-m-d H:i:s` texts as the rows
 * carry them (the first 19 characters of the row's `created`), the end formatted in the process's time zone.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationRunReportTimeWindow extends ValueObject
{
    /** @var int Seconds after the last row that still belong to the run (a model call booked after its row is written) */
    public const int TRAILING_SECONDS = 120;

    /** @var string The start, `Y-m-d H:i:s` */
    public string $from = '';

    /** @var string The end, `Y-m-d H:i:s` */
    public string $to = '';

    /**
     * The window of the rows; null when no row carries a `created` value.
     *
     * @param AIConversationRunReportMessageRows $rows the message rows
     * @return static|null
     */
    public static function fromRows(AIConversationRunReportMessageRows $rows): ?static
    {
        $createdTexts = [];
        foreach ($rows->getElements() as $row) {
            $createdText = $row->getCreatedAtText();
            if ($createdText !== '') {
                $createdTexts[] = $createdText;
            }
        }
        if ($createdTexts === []) {
            return null;
        }
        sort($createdTexts);
        $timeWindow = static::newInstance();
        $timeWindow->from = $createdTexts[0];
        $timeWindow->to = date('Y-m-d H:i:s', strtotime(end($createdTexts)) + static::TRAILING_SECONDS);
        return $timeWindow;
    }
}
