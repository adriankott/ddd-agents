# Web research: the package's search, page-read and screenshot tools

**Reader:** host developer

Contents: what ships · switching it on · the host hooks · degrading · testing in a host.

The package ships the agent's live web research: four tools in two packages, the skills `Web.Search` and
`Web.PageView`, the reading prompt and the configuration. A host switches it on, names its endpoints and, where it
needs to, decides payer, spend check, fee booking and the default country. It is off by default: nothing is offered and
no request is made until the host's `config/app/AI/webSearch.php` switches it on.

## What ships

| Piece | Where |
|---|---|
| `agent.webSearch` (`WebSearchMCPPackage`): `searchTheWeb`, `extractInformationFromWebPagesWithFastLLM`, `getWebPageAsMarkdown` | `src/Presentation/Mcp/Client/AI/` |
| `agent.webPageScreenshot` (`WebPageScreenshotMCPPackage`): `getWebPageAsScreenshot` | `src/Presentation/Mcp/Client/AI/` |
| The service `AIWebSearchService` and the host hooks `WebSearchHostService` | `src/Domain/AI/Services/WebSearch/` |
| Hit lists, merged hits (round robin over the queries) and their renderer, page markdown, readings with checked quotes, excerpts, payer, screenshot parts | `src/Domain/AI/Entities/WebSearch/` |
| The Argus repositories (endpoint, cache lifetime, timeout from the configuration) | `src/Domain/AI/Repo/Argus/WebSearch/` |
| Skills `Web.Search`, `Web.PageView` (sub-skill, needs vision) | `config/app/AI/Skills/Web/` |
| The reading prompt `AI.WebSearch.PageExtract` | `config/app/AI/Prompts/AI/WebSearch/PageExtract.md` |
| The configuration `AI.webSearch` | `config/app/AI/webSearch.php` |
| An evaluation template | `templates/web-search/Web.Search.json` of this skill |

Tool attributes: every tool reads untrusted content (`MCPToolReturnsUntrustedContent`; the screenshot marks only its URL,
the pictures are not screened), the three research results are never stubbed by the budget, every tool has 45 s, the
screenshot refreshes state. The search, the page read and the reading may run in parallel.

What the tools fix themselves instead of trusting the wording:

| Rule | Where it is enforced |
|---|---|
| Queries are plain words: `site:`, `-site:`, `OR`, `AND`, `\|`, quotes and `intitle:`-style prefixes are removed; the `site:` domains become the call's `domains` when the call has none and every query named a site, otherwise they are dropped; a note in the platform note says what changed, also for a query of more than 10 words | `WebSearchQueryNormalization`, `WebSearchCallQueries` |
| At most `hits.maxSearchCallsPerTurn` (3) `searchTheWeb` calls per turn; a further call is refused with the next move (answer from the found pages, or read one). Failed and duplicate calls do not count; outside the agent loop there is no limit; 0 switches it off | `WebSearchMCPPackage::throwIfSearchCallsOfTheTurnAreUsedUp()`, `ToolCallsOfTheCurrentTurn` |
| A page URL copied inexactly from a search result (percent escapes decoded, `ß` or an umlaut transliterated, another case) is read as the listed URL when exactly one listed hit matches; the platform note names it. A URL no search listed is read as given | `WebPageUrlCorrection` |

## Switching it on

1. Copy `config/app/AI/webSearch.php` into the host (the host's file replaces the package's whole) and set
   `enabled => true` and an endpoint per capability the host serves:
   ```php
   'endpoints' => [
       'search' => 'POST:/web/search',               // relative = through the Argus gateway
       'urlToMarkdown' => 'POST:/web/urlToMarkdown',
       'screenshot' => null,                          // no renderer: Web.PageView is not offered
   ],
   ```
   A capability can also be switched off with an endpoint (`capabilities.screenshot => false`). The wire shapes of the
   three endpoints are in the file's docblock; they are the request and answer shapes of a Perplexity-style search API,
   a url-to-markdown crawler and a headless-browser screenshot service.
2. Bind the skills in the host's `config/app/AI/skillPackages.php` (it replaces the package's):
   `AISkill::WEB_SEARCH => ['agent.webSearch']`, `AISkill::WEB_PAGE_VIEW => ['agent.webPageScreenshot']`. A package
   whose capabilities are unavailable is left out of the binding (`MCPPackage::isAvailable()`).
3. Gate the skill as any other (`AI.skillRequirements`: rights, flags), e.g. only for administrators.
4. Restart the workers (the binding and the configuration are read once per process).

## The host hooks (`WebSearchHostService`)

A host replaces any of them by a same-path subclass `App\Domain\AI\Services\WebSearch\WebSearchHostService`:

| Method | Default | Typical host answer |
|---|---|---|
| `findDefaultCountryCode(?AgentToolCallProvenance)` | null (the engine decides) | the country of the space's location or world |
| `findPayer(?AgentToolCallProvenance): WebSearchPayer` | the signed-in account | the space's account, with the entity the costs are attributed to (project, location) |
| `throwIfWebSearchSpendingIsRefused(WebSearchPayer)` | allows | throw a 4xx framework exception when the AI budget is spent (the model reads the message) |
| `bookWebSearchRequests(WebSearchPayer, WebSearchHitListFilters, int, float)` | books nothing | write the fee into the host's cost ledger: per real request the fee the endpoint reports (`data.billing.feeUsd`) or else `hits.feeUsdPerRequestByMode`; never a cache hit of the package or of the endpoint (`data.cached`), never a failure |
| `buildNoteAfterWebPagesRead(WebPageMarkdowns, AgentToolCallProvenance): ?string` | null | store facts verified on the host's own site; the returned line leads the result |

The readings are model calls: ddd-ai's usage recorder books them for the payer set on each reading
(`billAICostsTo()`), under `AgentAIUsageScope::AI_WEB_PAGE_EXTRACT`, inside the tool call's envelope. Without an
account no page is read by a model (the reading fails with "no account to bill the reading to").

The reading model is `reading.modelName` (default: the package's fast model without reasoning). Every limit of the tools
is in the configuration: queries per search (5), pages per read (5), pages per reading (10), the render budget
(48,000 characters), cache lifetimes and timeouts per capability (`cacheTtlSeconds`, `timeoutSeconds`), and whether a
capability's answers are cached in the database too (`cacheInDatabase`; a screenshot is cached in memory only unless
`screenshot: true` — megabytes of base64 per capture).

## Degrading

- Off, or a capability without an endpoint: the packages are not offered; a direct call is refused with a sentence the
  model reads; the service answers failures on the returned objects (an unanswered hit list, a page with reason
  `CAPABILITY_OFF`, a screenshot part of height 0), never an exception.
- A page read refuses before any crawl: a search engine's results page, a host without a DNS record, a private address
  (`PublicUrlGuard`); a screenshot is rendered only when every resolved address of the host is public.
- Without Imagick a screenshot is neither trimmed nor cut: the first part is the whole capture as one picture.
- A failed render keeps its reason (`reason` of the answer, at the top, under `data` or `error`; a rejected call:
  `TIMEOUT` or `FETCH_FAILED`; the package's own `NOT_PUBLIC` and `CAPABILITY_OFF`) and the model reads one sentence per
  reason with the next move (`BOT_CHALLENGE` → the text with `getWebPageAsMarkdown`; `TIMEOUT` → one retry; `BLOCKED`,
  `UNRESOLVABLE` → no retry); an unknown code is named in the generic sentence. A failed render is never cached.
- The screenshot line names the parts of the same page and device already shown in the turn
  (`WebPageScreenshotPartsOfTheCurrentTurn`); reopening one gets a note, never a refusal (its pictures may have left the
  model's view).
- A query that names an unambiguous time window (`today`, `this week`, `this month`) without `recency` is searched with
  that window, and the note says so; news words without a window get a note suggesting `recency`. A reading's facts
  end with `[source: site, Reading n]`, so the source travels into a table row.
- A crawl or a search that times out is not cached; the next call asks again.

## Testing in a host

- The schema and attributes: `MCPPackageDocumentation::createFromClassName(WebSearchMCPPackage::class)`.
- The tools call the live internet and bill: run the evaluation template with `--ignore-budget`, translated into the
  host's language and with the host's space key on every case.
- Changed wording of the skill or a tool description is model-facing: rerun the evaluation before release.
