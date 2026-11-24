<?php

declare(strict_types=1);

/**
 * The store of tool-result pictures — read by
 * {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallImageStoreService} as `Config::get('AI.toolCallImageStore')`
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Images\AgentToolCallImageStoreSettings}). An application replaces
 * this file whole with its own `config/app/AI/toolCallImageStore.php`; a missing key keeps its default. The key of
 * 0.3.7–0.3.11, `AI.toolCallImageSpool`, is still read (reported once per process): move it here.
 *
 * A picture a tool returns without a store of its own (a browser viewport, a workspace image) is normalized ONCE when
 * its call completes (the wire profile) and written to the tiers; its persisted reference names the store, and every
 * later model call re-reads the SAME bytes — the tool result stays byte-identical across worker invocations, turns and
 * a resume. Conversation rows hold the reference only (sha256, type, size), never the bytes.
 *
 * Tiers — every one optional; a read goes L1 → one L2 batch → L3 in parallel, and fills the faster tiers again:
 * - `processCache` (L1): a cache group local to the process (null = Core's APCu group). `maxBytes` bounds what this
 *   process writes per `ttlSeconds` window.
 * - `sharedCache` (L2): a cache group the workers share — `'redis'` (Redis or Valkey; any group of Core's Cache API).
 *   One MGET per model call, writes in one pipeline, each entry with `ttlSeconds`; `extendTtlOnRead` gives a read
 *   entry the full TTL again (one pipelined EXPIRE). Null = Core's default group: APCu unless the host changes it,
 *   which is local to the process — then L2 is skipped and reported once per process.
 * - `objectStore` (L3): a Core object store (`config/app/Common/ObjectStorage.php` + `OBJECT_STORAGE_<NAME>_*`), keys
 *   `<keyPrefix><agent space id>/<sha256>`. The package never sets a lifecycle: give the prefix a rule (e.g. expire
 *   after 30 days) in the bucket. A picture is durable only once L3 acknowledged its write; a failed write leaves it
 *   in the caches and writes an issues-log entry. An unconfigured store is reported once per process.
 *
 * L1 + L2 without an object store behaves like the 0.3.7 spool (the picture lives for the L2 TTL); `enabled` false
 * keeps the pictures inline (they leave at the end of their worker invocation). Nothing here throws.
 *
 * `wireProfile`: what a captured picture is normalized to — the long edge, the megapixels, `webp` or `jpeg` and the
 * encoder quality. A WebP or JPEG within the limits keeps its bytes; anything else is decoded by Core's pinned
 * `RasterImageDecoder`, scaled, stripped and encoded. The sha256 is taken over the wire form.
 *
 * `retention`: at a turn change the picture-bearing results of earlier turns beyond the newest `newestImageResults`
 * leave the context — each picture becomes a one-line placeholder naming `viewEarlierToolImage` (skill Agent.Images);
 * the current turn keeps all its pictures, at most `maxPicturesOnWire` per call (the oldest leave first). The cut
 * moves only at a turn change, so the prompt cache keeps its prefix within a turn.
 */
return [
    'enabled' => true,
    'processCache' => [
        'enabled' => true,
        'cacheGroup' => null,
        'maxBytes' => 67_108_864,
        'ttlSeconds' => 600,
    ],
    'sharedCache' => [
        'enabled' => true,
        'cacheGroup' => null,
        'ttlSeconds' => 1800,
        'extendTtlOnRead' => true,
    ],
    'objectStore' => [
        'enabled' => true,
        'objectStorage' => 'agentToolImages',
        'keyPrefix' => 'agent-tool-images/',
    ],
    'wireProfile' => [
        'enabled' => true,
        'maxEdgePixels' => 2048,
        'maxMegapixels' => 3.75,
        'format' => 'webp',
        'quality' => 85,
    ],
    'retention' => [
        'enabled' => true,
        'newestImageResults' => 4,
        'maxPicturesOnWire' => 20,
    ],
];
