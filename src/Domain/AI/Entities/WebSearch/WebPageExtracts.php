<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The extracts of ONE extraction call, one per page in the order asked ({@see WebPageExtract}), and the agent's view of
 * them: pages with facts first (FOUND, PARTIAL, SNIPPET_ONLY), then one line per page that states
 * nothing for the task, then one line listing the pages that could not be read.
 *
 * @property WebPageExtract[] $elements
 * @method WebPageExtract[] getElements()
 */
class WebPageExtracts extends ObjectSet
{
    /** The order in which pages with facts are shown. */
    public const array STATUS_ORDER_OF_PAGES_WITH_FACTS = [
        WebPageExtract::STATUS_FOUND,
        WebPageExtract::STATUS_PARTIAL,
        WebPageExtract::STATUS_SNIPPET_ONLY,
    ];

    /** How many pages carry facts the agent can use. */
    public function countPagesWithFacts(): int
    {
        $pagesWithFacts = 0;
        foreach ($this->getElements() as $webPageExtract) {
            if ($webPageExtract->hasFacts()) {
                $pagesWithFacts++;
            }
        }
        return $pagesWithFacts;
    }

    /**
     * The agent's view of the whole call: the task, the pages with facts as numbered blocks (each cut at
     * `$extractCharCapPerPage`), one line naming the pages that state nothing for the task, one line naming the pages
     * that could not be read and why.
     */
    public function buildLlmMarkdown(string $extractionTask, int $extractCharCapPerPage): string
    {
        $sections = ['Extraction task: "' . trim($extractionTask) . '" — ' . $this->count() . ' pages, read by a fast model:'];
        $sourceNumber = 0;
        foreach (self::STATUS_ORDER_OF_PAGES_WITH_FACTS as $status) {
            foreach ($this->getElements() as $webPageExtract) {
                if ($webPageExtract->status === $status && $webPageExtract->hasFacts()) {
                    $sourceNumber++;
                    $sections[] = $webPageExtract->buildLlmMarkdownBlock($sourceNumber, $extractCharCapPerPage);
                }
            }
        }
        $pagesWithoutTheAnswer = [];
        $unreadablePages = [];
        foreach ($this->getElements() as $webPageExtract) {
            if ($webPageExtract->status === WebPageExtract::STATUS_NOT_ON_PAGE) {
                $pagesWithoutTheAnswer[] = $webPageExtract->url;
                continue;
            }
            if (!$webPageExtract->hasFacts()) {
                $unreadablePages[] = $webPageExtract->url . ' (' . ($webPageExtract->failureReason ?? strtolower($webPageExtract->status)) . ')';
            }
        }
        if ($sourceNumber === 0) {
            $sections[] = 'No page states what the task asks for.';
        }
        if ($pagesWithoutTheAnswer !== []) {
            $sections[] = 'Read, nothing on the task: ' . implode(', ', $pagesWithoutTheAnswer);
        }
        if ($unreadablePages !== []) {
            $sections[] = 'Not readable: ' . implode(', ', $unreadablePages);
        }
        return implode("\n\n", $sections);
    }
}
