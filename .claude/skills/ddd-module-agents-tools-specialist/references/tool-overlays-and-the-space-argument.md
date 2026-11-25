# Tool overlays and the space argument

**Reader:** host developer

Contents: tool overlays · the space argument.

How an application changes a package tool without a subclass: its rights and texts by tool NAME, and the name of the
space argument on the wire. Both are config; the schema caches follow them (`MCPToolOverlays::getSchemaConfigurationFingerprint()`
is part of the documentation cache keys).

## Tool overlays (`config/app/AI/toolOverlays.php`, `AI.toolOverlays`)

The package ships `[]`. An application's own file at the same path replaces it:

```php
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;

return [
    'getTaskSchedules' => [
        'description' => 'Lists the jobs of the shop: when each runs next and what it does.',
        'summary' => 'Lists the jobs',
        'parameters' => ['agentSpaceId' => 'The shop whose jobs are listed.'],
    ],
    'deleteTaskSchedule' => ['accountRights' => ['manageShopJobs'], 'ownerFacingSubject' => 'Job'],
    'startBackgroundTask' => ['accountRights' => []],
];
```

| Key | Replaces | Read in |
|---|---|---|
| `accountRights` | the rights of `MCPRequiresAccountRights` on the method AND the package class; `[]` = no right for this tool | `MCPToolEntitlement::createFromReflectionMethod()` (offer, wire, executor refusal) |
| `description` | the tool description the model reads | `MCPTool::createFromReflectionMethod()` |
| `summary` | `MCPToolSummary` (the tools line, the mandate summary) | `MCPTool`, `MCPToolExecutorService` |
| `parameters` | the description of a parameter, by its name on the wire (or `agentSpaceId` for the space argument under any name) | `MCPSchema::applyApplicationSchemaConfiguration()` |
| `ownerFacingSubject` | `MCPToolOwnerFacingSubject` (the run report's title of a write) | `AgentRunReport::ownerFacingTitleForWriteTool()` |

- Keyed by tool NAME: one entry changes the tool in every package that has a method of that name; names are unique
  in practice (the bundle refuses ambiguous ones).
- An unknown key is kept (`MCPToolOverlay::$unknownKeys`) and reported by `app:agent:check-installation`, as is an
  entry whose tool name no bound package has. Nothing fails at run time.
- Classes: `MCPToolOverlays` (`getApplicationOverlays()`, `findOverlayOfTool()`, `replaceCurrent()` / `resetCurrent()`
  for tests), `MCPToolOverlay`.

## The space argument (`config/app/AI/agentSpaceToolArgument.php`, `AI.agentSpaceToolArgument`)

`['name' => 'agentSpaceId', 'description' => null]` by default. An application whose own word for the space is
another (shopId, projectId) sets `name` (an identifier) and optionally `description`:

- every input schema that has `agentSpaceId` carries the configured name in its place, with the configured
  description (`MCPSchema::applyApplicationSchemaConfiguration()`); the request DTOs keep `agentSpaceId`;
- the resolver reads the configured name first, then `agentSpaceId`
  (`AgentSpacesService::findRequestedAgentSpaceIdOfToolArguments()`); `findAgentSpaceOfToolCall()` receives the whole
  tool arguments (`AgentToolCallProvenance::$toolArguments`, set by the executor), so a host resolver override reads
  its own argument;
- the conversation default fills the configured name, and the executor writes the resolved space back into the DTO's
  `agentSpaceId` (`MCPToolExecutorService::applyResolvedAgentSpaceToRenamedSpaceArgument()`).

Class: `AgentSpaceToolArgument` (`current()`, `replaceCurrent()` / `resetCurrent()` for tests).
