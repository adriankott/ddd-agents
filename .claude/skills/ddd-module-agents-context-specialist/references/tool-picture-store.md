# The tool picture store (`AI.toolCallImageStore`)

**Reader:** host developer

Contents: 1. What happens to a picture · 2. Host setup · 3. Measuring · 4. Warnings

Pictures a tool returns without a store of its own (a browser viewport, a workspace image, a rendered chart) are kept
by the package so that every later model call sends the SAME bytes: the tool result stays byte-identical across worker
invocations, turns and a resume, and the prompt cache keeps its prefix. Conversation rows hold the reference only
(`ToolCallImageReference`: store `spool`, id `<scope>/<sha256>`, type, size, `isDurable`), never the bytes.

Service: `AgentToolCallImageStoreService`. Settings: `AgentToolCallImageStoreSettings`, file
`config/app/AI/toolCallImageStore.php` (an application's own copy replaces it whole; a missing key keeps its default).
The key of 0.3.7–0.3.11, `AI.toolCallImageSpool`, is still read and mapped (`cacheGroup` → `sharedCache.cacheGroup`,
`cacheTtlSeconds` → `sharedCache.ttlSeconds`, `objectStorage`, `objectKeyPrefix`), reported once per process: move it.

## 1. What happens to a picture

1. **Capture** (the call completes, `storeInlineToolResultImages()`): the full check runs once — magic bytes, Core's
   pinned `RasterImageDecoder`, the pixel cap — and the picture is brought to the **wire profile**: long edge
   `maxEdgePixels` (2048), at most `maxMegapixels` (3.75), WebP (or JPEG) at `quality` 85. A WebP or JPEG within the
   limits keeps its bytes. The sha256 is taken over the wire form. Then it is written to the tiers:
   - **L1** `processCache`: a cache group of this process (APCu by default), at most `maxBytes` (64 MB) written per
     `ttlSeconds` (600) window;
   - **L2** `sharedCache`: a cache group all workers share, one pipeline per capture, TTL `ttlSeconds` (1800);
   - **L3** `objectStore`: a Core object store, key `<keyPrefix><scope>/<sha256>` (scope = agent space id, else
     `account-<id>`, else `conversation-<id>`). The reference is **durable** only once L3 acknowledged the write.
   A failing L3 write leaves the picture in L1/L2 and writes an issues-log warning. With no tier at all (or
   `enabled` false) the picture stays inline: it rides in its own worker invocation and leaves at its end.
2. **Read** (every model call, `readStoredToolCallImages()`, one call for all pictures of the call): L1 → the L1
   misses in ONE L2 read (MGET; the hits get the full TTL again, one EXPIRE pipeline, `extendTtlOnRead`) → the L2
   misses from L3 **in parallel** (`ParallelReadableObjectStorage`, every GET in flight together) → what L2 or L3
   delivered is written back to L1 (and L3's to L2). A read checks the sha256 and the header only; it never decodes.
   A resume (a worker that held nothing) therefore pays one L3 round trip for the pictures still in context, and the
   calls after it are served from L1/L2.
3. **Retention** (`retention`): at a turn change the picture results of EARLIER turns keep their pictures only for the
   newest `newestImageResults` (4); older ones leave the context and each picture becomes one line:
   `[image <id12>, WxH — no longer attached; to see it again call viewEarlierToolImage with imageId "<id12>" (skill Agent.Images)]`.
   The current turn keeps all its pictures, at most `maxPicturesOnWire` (20) per call (oldest results leave first).
   The cut moves only at a turn change, so every call of a turn renders the same history; the call that moves it
   puts its cache marker on the last text entry before the first newly retired result (instead of the previous
   call's entry, which lies behind the change). Only the pictures still in context are read on a resume.
   `viewEarlierToolImage` (`AgentImagesMCPPackage`, package `agent.images`) attaches one again from the store with
   the same reference and the trust of the call that produced it; it never re-runs the original tool.

Nothing in the store throws into a tool call or a model call: a missing picture is a miss (the result carries the
"no longer on the wire" marker), a failing tier is skipped.

## 2. Host setup

**Every tier is optional.** Choose what the installation needs:

| Setup | Effect |
|---|---|
| nothing configured (package defaults, APCu only) | L1 only: a picture rides while the same process serves the conversation; another worker misses it. L2 is skipped (APCu is local) and reported once per process; L3 is reported missing once per process. |
| L1 + L2 (`sharedCache.cacheGroup` = a shared group), no object store | the 0.3.7 spool: every worker reads the picture for the L2 TTL (extended on each read); after a long pause it is gone |
| L1 + L2 + L3 | durable pictures: a resume after days still sends the same bytes |
| `enabled` false | pictures inline, one invocation (as before 0.3.7) |

**L2 on a shared Redis or Valkey.** Use a group of Core's Cache API (`'redis'`, `'redisSentinel'`); the batch reads
and writes work on Redis and Valkey (phpredis pipelines; Predis, cluster and Relay fall back to one command per key).
Every picture key carries a TTL. Pick `maxmemory-policy` by what the instance holds:
- a shared instance that also holds keys WITHOUT a TTL (sessions, queues, locks, rankings) → `volatile-lru` (or
  `volatile-ttl`): under memory pressure only keys with a TTL are evicted, never the keys without one;
- a dedicated cache instance (every key may be evicted) → `allkeys-lru`;
- never `noeviction` for L2: picture writes then fail and the store skips L2. Size: a wire-profile WebP is typically 50–400 KB; pictures × TTL window is the L2 footprint.

**L3 object store.** Configure a Core object store named `agentToolImages` (or the name in `objectStore.objectStorage`)
in the application's Core object storage configuration (config/app/Common/ObjectStorage.php) or by environment:

```
OBJECT_STORAGE_AGENTTOOLIMAGES_DRIVER=s3
OBJECT_STORAGE_AGENTTOOLIMAGES_ENDPOINT=https://<object storage endpoint>
OBJECT_STORAGE_AGENTTOOLIMAGES_REGION=<region>
OBJECT_STORAGE_AGENTTOOLIMAGES_BUCKET=<bucket>
OBJECT_STORAGE_AGENTTOOLIMAGES_ACCESS_KEY=<from the secret store>
OBJECT_STORAGE_AGENTTOOLIMAGES_SECRET_KEY=<from the secret store>
```

(the s3 driver needs `async-aws/s3` in the application; details: Core's `ddd-service-specialist` › Object Storage).
**The lifecycle is the host's:** the package never deletes and never sets a bucket rule. Give the key prefix
(`agent-tool-images/`) an expiry rule in the bucket (e.g. 30 or 90 days, matching how long conversations are resumed);
an expired picture is a miss, its result then carries the marker.

**Example — with an object store:**

```php
// config/app/AI/toolCallImageStore.php of the application
return [
    'sharedCache' => ['cacheGroup' => 'redis', 'ttlSeconds' => 1800],
    'objectStore' => ['objectStorage' => 'agentToolImages', 'keyPrefix' => 'agent-tool-images/'],
];
```

**Example — without an object store:**

```php
return [
    'sharedCache' => ['cacheGroup' => 'redis', 'ttlSeconds' => 7200, 'extendTtlOnRead' => true],
    'objectStore' => ['enabled' => false],   // no "object store missing" warning
    'retention' => ['newestImageResults' => 2],
];
```

**Skill binding.** The re-view tool is bound in the package's `config/app/AI/skillPackages.php`
(`AISkill::AGENT_IMAGES => [AgentImagesMCPPackage::class]`). A host with its own `skillPackages.php` adds that line;
the package is offered only while the store and the retention are on (`AgentImagesMCPPackage::isAvailable()`).

## 3. Measuring

Every request snapshot records the pictures it sent (`toolResultImagesOnWire`) and where the store found them
(`toolCallImageProcessCacheHits`, `…SharedCacheHits`, `…ObjectStoreHits`, `toolCallImageMisses`,
`toolCallImageReadMs`); the wire build time is `loopStepOverheadMs.wire`. `app:agent:cache-diff` prints the wire
build p50/p95 with and without pictures and the L1/L2/L3/miss sums of the conversation.

## 4. Warnings (issues log, once per process, never an exception)

| Warning | Cause | Fix |
|---|---|---|
| `legacyConfig` | the host still ships `toolCallImageSpool.php` | move the keys to `toolCallImageStore.php` |
| `sharedCacheProcessLocal` | `sharedCache.cacheGroup` resolves to APCu | a shared group (`'redis'`), or `sharedCache.enabled` false |
| `objectStoreMissing` | the object store is not configured | configure it (`OBJECT_STORAGE_AGENTTOOLIMAGES_*`), or `objectStore.enabled` false |
| a store failure (write / read / shared cache write) | the backend failed | the backend; the picture stays usable through the other tiers |
