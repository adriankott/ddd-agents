<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\WebSearch\Fixtures;

use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdowns;
use DDD\Domain\AI\Services\WebSearch\WebPageTextSource;
use RuntimeException;

/**
 * A page reader that answers every URL it is handed with a page of its own (`fetchedVia` = `scripted`), except the
 * URLs in `$unansweredUrls`; it records the URLs it was handed and throws when `$failsWith` is set.
 */
class ScriptedWebPageTextSource implements WebPageTextSource
{
    public bool $available = true;

    public ?string $failsWith = null;

    /** @var string[] */
    public array $unansweredUrls = [];

    /** @var string[] The URLs of the last read */
    public array $handedUrls = [];

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function getWebPagesAsMarkdown(array $urls, bool $noCache = false, ?float $timeoutSeconds = null): WebPageMarkdowns
    {
        $this->handedUrls = $urls;
        if ($this->failsWith !== null) {
            throw new RuntimeException($this->failsWith);
        }
        $webPageMarkdowns = WebPageMarkdowns::newInstance();
        foreach ($urls as $url) {
            if (in_array($url, $this->unansweredUrls, true)) {
                continue;
            }
            $webPageMarkdown = WebPageMarkdown::newInstance();
            $webPageMarkdown->url = $url;
            $webPageMarkdown->ok = true;
            $webPageMarkdown->title = 'Page';
            $webPageMarkdown->markdown = 'The text of ' . $url . ', long enough to be no challenge page.';
            $webPageMarkdown->fetchedVia = 'scripted';
            $webPageMarkdowns->add($webPageMarkdown);
        }
        return $webPageMarkdowns;
    }
}
