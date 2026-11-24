<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Documents;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The pages one read of a document's text returned ({@see AgentDocumentText::renderPagesForModel()}): the text, the
 * page range, and the next unread page when the read stopped at the character limit.
 *
 * @phpstan-consistent-constructor
 */
class AgentDocumentPagesRead extends ValueObject
{
    /** @var string The text of the pages, each under a header line */
    public string $text = '';

    /** @var int The first page returned */
    public int $fromPage = 1;

    /** @var int The last page returned (0 = none) */
    public int $toPage = 0;

    /** @var int|null The next page a further read starts with; null when the read reached the end */
    public ?int $nextPage = null;
}
