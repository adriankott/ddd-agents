# Web research: the package's search and page-read tools

**Reader:** host developer

Contents: what ships · switching it on · the host hooks · degrading · testing in a host.

The package ships the agent's live web research: three tools in one package, the skill `Agent.Web.Search`, the reading
prompt and the configuration. Page screenshots are the sibling skill `Agent.Web.Pages` of the package mgamadeus/ddd-agent-browser
(tool `getWebPageAsScreenshot`): a host with that package names `Agent.Web.Pages` in `subSkillNames` of its own copy of
`Agent/Web/Search.md` and binds it (the package's file names no skill of a package it does not require). A host switches it on, names its endpoints and, where it
needs to, decides payer, spend check, fee booking and the default country. It is off by default: nothing is offered and
no request is made until the host's `config/app/AI/webSearch.php` switches it on.

## What ships

| Piece | Where |
|---|---|
| `agent.web.search` (`WebSearchMCPPackage`): `searchTheWeb`, `extractInformationFromWebPagesWithFastLLM`, `getWebPageAsMarkdown` | `src/Presentation/Mcp/Client/Agents/` |
| The service `AIWebSearchService` and the host hooks `WebSearchHostService` | `src/Domain/AI/Services/WebSearch/` |
| Hit lists, merged hits (round robin over the queries) and their renderer, page markdown, readings with checked quotes, excerpts, payer | `src/Domain/AI/Entities/WebSearch/` |
| The Argus repositories (endpoint, cache lifetime, timeout from the configuration) | `src/Domain/AI/Repo/Argus/WebSearch/` |
| Skill `Agent.Web.Search` (right `useWeb`) | `config/app/AI/Skills/Agent/Web/Search.md` |
| The reading prompt `AI.WebSearch.PageExtract` | `config/app/AI/Prompts/AI/WebSearch/PageExtract.md` |
| The configuration `AI.webSearch` | `config/app/AI/webSearch.php` |
| An evaluation template | `templates/web-search/Agent.Web.Search.json` of this skill |

Tool attributes: every tool reads untrusted content (`MCPToolReturnsUntrustedContent`), the three research results are
never stubbed by the budget, every tool has 45 s; the package requires the right `AgentAccountRights::USE_WEB`. The
search, the page read and the reading may run in parallel.

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
   ],
   ```
   A capability can also be switched off with an endpoint (`capabilities.urlToMarkdown => false`). The wire shapes of
   the two endpoints are in the file's docblock; they are the request and answer shapes of a Perplexity-style search
   API and a url-to-markdown crawler.
2. Bind the skills in the host's `config/app/AI/skillPackages.php` (it replaces the package's):
   `AISkill::AGENT_WEB_SEARCH => [WebSearchMCPPackage::class]`. A package
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
| `findMaxSearchCallsPerTurn(AgentToolCallProvenance): ?int` | null (`hits.maxSearchCallsPerTurn`, default 3) | more searches for a research sub-agent, fewer in a customer chat; 0 = no limit |

The entities of the research are built as the application's class: a same-path subclass of `WebPageMarkdown`,
`WebSearchHitList` or `WebPageExtract` is what a search, a read or a reading returns (the package maps every Argus
repository with `toEntity(entityInstance: X::newInstance())`; `ArgusRepositoriesAreNeverMappedOntoThePackageClassTest`).
A subclass of `WebPageMarkdown` adds its own lines between the header and the page's marked text
(`buildPlatformLinesBelowHeader()`, each line ending in a newline; above the marks, so the page cannot imitate them)
and words the sentence after a failed read (`buildContinueWithoutThePageSentence(bool $noToolCanReachTheAddress)`) and
after a refused search results page (`buildSentenceAfterSearchResultsPageRefusal()`). A tool parameter typed on
`AIWebSearchService` or `WebSearchHostService` receives the application's same-path subclass; no alias is needed.

The readings are model calls: ddd-ai's usage recorder books them for the payer set on each reading
(`billAICostsTo()`), under `AgentAIUsageScope::AI_WEB_PAGE_EXTRACT`, inside the tool call's envelope. Without an
account no page is read by a model (the reading fails with "no account to bill the reading to").

The reading model is `reading.modelName` (default: the package's fast model without reasoning). Every limit of the tools
is in the configuration: queries per search (5), pages per read (5), pages per reading (10), the render budget
(48,000 characters), cache lifetimes and timeouts per capability (`cacheTtlSeconds`, `timeoutSeconds`), and whether a
capability's answers are cached in the database too (`cacheInDatabase`).

## Degrading

- Off, or a capability without an endpoint: the packages are not offered; a direct call is refused with a sentence the
  model reads; the service answers failures on the returned objects (an unanswered hit list, a page with reason
  `CAPABILITY_OFF`), never an exception.
- A page read refuses before any crawl: a search engine's results page, a host without a DNS record, a private address
  (`PublicUrlGuard`); it uses `PublicUrlGuard::classifyHost()`: a host without a DNS record is `UNRESOLVABLE` ("cannot be resolved (check the
  spelling)"), only a private or reserved address is `NOT_PUBLIC` (page read: `BLOCKED`).
- The reasons a page read shares with the screenshot of the package mgamadeus/ddd-agent-browser have ONE sentence each in
  `WebFetchFailureReasons` (`UNRESOLVABLE`, `UNSUPPORTED_TYPE`, `TOO_LARGE` → retrying is pointless, `NO_CONTENT`,
  `TOO_MANY_REDIRECTS` → no retry); an unknown code is named in the generic sentence. Add a shared code there, a
  capability's own code in its entity.
- A query that names an unambiguous time window without `recency` is searched with that window, and the note says
  so: `today`, `this week`, `this month`, "past N days" in English, German, French, Spanish and Italian
  (`WebSearchCallQueries::RECENCY_WINDOWS_BY_PHRASE`, `PAST_DAYS_PATTERNS`), and the name of the current month in the
  conversation's zone (`November 2026` in November 2026 → month; `may`, `mars`, `mai` only with the year). Another
  month with its year, and news words without a window, get a note suggesting `recency`.
- A page read that fails as if the page did not exist (`FETCH_FAILED`) for a URL no search of the conversation listed
  names the listed pages of the same site (`WebPageUrlCorrection::buildUnlistedFailedUrlNote()`): the model built a
  path segment itself. Two or more pages read: the note asks for every page's URL in the answer. A reading's facts
  end with `[source: site, Reading n]`, so the source travels into a table row.
- A crawl or a search that times out is not cached; the next call asks again.

## Testing in a host

- The schema and attributes: `MCPPackageDocumentation::createFromClassName(WebSearchMCPPackage::class)`.
- The tools call the live internet and bill: run the evaluation template with `--ignore-budget`, translated into the
  host's language and with the host's space key on every case.
- Changed wording of the skill or a tool description is model-facing: rerun the evaluation before release.
- The judge reads the run's text trace, never the pictures: a visual case judges the calls (parts, device, no part
  reopened) and that each opened part was described, never whether a visual statement is right.
