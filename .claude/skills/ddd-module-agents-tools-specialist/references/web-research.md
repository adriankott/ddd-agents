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
| `bookWebSearchRequests(WebSearchPayer, WebSearchHitListFilters, int, float)` | books nothing | write the fee (`hits.feeUsdPerRequestByMode` × real requests; never a cache hit or a failure) into the host's cost ledger |
| `buildNoteAfterWebPagesRead(WebPageMarkdowns, AgentToolCallProvenance): ?string` | null | store facts verified on the host's own site; the returned line leads the result |

The readings are model calls: ddd-ai's usage recorder books them for the payer set on each reading
(`billAICostsTo()`), under `AgentAIUsageScope::AI_WEB_PAGE_EXTRACT`, inside the tool call's envelope. Without an
account no page is read by a model (the reading fails with "no account to bill the reading to").

The reading model is `reading.modelName` (default: the package's fast model without reasoning). Every limit of the tools
is in the configuration: queries per search (5), pages per read (5), pages per reading (10), the render budget
(48,000 characters), cache lifetimes and timeouts per capability (`cacheTtlSeconds`, `timeoutSeconds`).

## Degrading

- Off, or a capability without an endpoint: the packages are not offered; a direct call is refused with a sentence the
  model reads; the service answers failures on the returned objects (an unanswered hit list, a page with reason
  `CAPABILITY_OFF`, a screenshot part of height 0), never an exception.
- A page read refuses before any crawl: a search engine's results page, a host without a DNS record, a private address
  (`PublicUrlGuard`); a screenshot is rendered only when every resolved address of the host is public.
- Without Imagick a screenshot is neither trimmed nor cut: the first part is the whole capture as one picture.
- A crawl or a search that times out is not cached; the next call asks again.

## Testing in a host

- The schema and attributes: `MCPPackageDocumentation::createFromClassName(WebSearchMCPPackage::class)`.
- The tools call the live internet and bill: run the evaluation template with `--ignore-budget`, translated into the
  host's language and with the host's space key on every case.
- Changed wording of the skill or a tool description is model-facing: rerun the evaluation before release.
