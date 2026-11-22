<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\WebSearch;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdowns;
use DDD\Domain\AI\Entities\WebSearch\WebSearchHitListFilters;
use DDD\Domain\AI\Entities\WebSearch\WebSearchPayer;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\Service;

/**
 * What the application decides around the web research of the package: the search country and the payer of a call,
 * whether the payer may spend, the booking of the search fee, and what follows a page read. An application replaces
 * any of these by a same-path subclass (`App\Domain\AI\Services\WebSearch\WebSearchHostService`); the defaults below
 * work without one: no default country, the signed-in account pays, every spend is allowed, nothing is booked, nothing
 * follows a page read.
 *
 * The page readings themselves are model calls: their cost is recorded by ddd-ai's usage recorder for the payer the
 * package sets on each reading, like every other model call of the application.
 */
class WebSearchHostService extends Service
{
    /**
     * The country whose web a search covers when the model names none (ISO 3166-1 alpha-2, e.g. `DE`), from the call's
     * agent space; null leaves it to the engine.
     */
    public function findDefaultCountryCode(?AgentToolCallProvenance $callProvenance): ?string
    {
        return null;
    }

    /**
     * Who pays for the searches and readings of the call, from the call's agent space or the signed-in account. Never
     * a tool argument.
     */
    public function findPayer(?AgentToolCallProvenance $callProvenance): WebSearchPayer
    {
        $authAccount = AuthService::instance()->getAccount();
        return WebSearchPayer::forAccount($authAccount instanceof Account ? $authAccount : null);
    }

    /**
     * Refuses the search or reading before any request when the payer may not spend (a spent AI budget). Throws a 4xx
     * framework exception whose message says what did not happen and what to do instead; the model reads it.
     */
    public function throwIfWebSearchSpendingIsRefused(WebSearchPayer $webSearchPayer): void
    {
    }

    /**
     * Books the fee of `$billableRequestCount` search requests that really went to the engine and answered (never a
     * cache hit of the package or of the endpoint (`data.cached`), never a failure): `$feeUsd` sums, per request, the
     * fee the endpoint reports (`data.billing.feeUsd`) or else `AI.webSearch.hits.feeUsdPerRequestByMode` of the mode
     * (0 when neither gives one). Called once per search call with at least one billable request;
     * a failure here is logged by the package and never fails the search.
     */
    public function bookWebSearchRequests(
        WebSearchPayer $webSearchPayer,
        WebSearchHitListFilters $webSearchHitListFilters,
        int $billableRequestCount,
        float $feeUsd,
    ): void {
    }

    /**
     * Runs after `getWebPageAsMarkdown` read pages (only the pages that were read), e.g. to store facts the
     * application verifies on its own site. Returns one line the model reads at the top of the result, or null.
     */
    public function buildNoteAfterWebPagesRead(WebPageMarkdowns $readWebPageMarkdowns, AgentToolCallProvenance $callProvenance): ?string
    {
        return null;
    }
}
