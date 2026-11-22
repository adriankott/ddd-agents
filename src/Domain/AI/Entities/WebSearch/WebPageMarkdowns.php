<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * Several fetched web pages of ONE read (`getWebPageAsMarkdown` with `urls`): one
 * {@see WebPageMarkdown} per URL in the order asked, failures ON the element (`ok` false + reason), never missing.
 *
 * @property WebPageMarkdown[] $elements
 * @method WebPageMarkdown[] getElements()
 */
class WebPageMarkdowns extends ObjectSet
{
}
