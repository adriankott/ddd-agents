<?php

declare(strict_types=1);

/**
 * The durable spool of tool-result pictures — read by
 * {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallImageSpoolService} as `Config::get('AI.toolCallImageSpool')`.
 * An application replaces this file whole with its own `config/app/AI/toolCallImageSpool.php`.
 *
 * A picture a tool returns without a store of its own (a browser viewport, a workspace image) is written to the spool
 * when the call completes; its persisted reference then names the spool, and every later model call re-reads the SAME
 * bytes. The frozen tool result stays byte-identical across worker invocations and turns until it leaves on purpose:
 * its result is stubbed or cleared, the picture cap drops it, or a compaction removes it.
 *
 * `enabled`: false keeps the single-turn behaviour (the picture leaves at the end of its worker invocation).
 * `objectStorage`: the name of a Core object store (`config/app/Common/ObjectStorage.php` + `OBJECT_STORAGE_<NAME>_*`);
 * when that store is configured the bytes go there under `<objectKeyPrefix><conversation id>/<sha256>` (give the
 * prefix a lifecycle rule, e.g. 30 days). Otherwise the bytes go to the application cache (`Cache::instance()`) for
 * `cacheTtlSeconds` — a cache shared by the workers (Redis) is needed; a per-process cache (APCu under the CLI) finds
 * nothing in the next worker and the picture leaves as before.
 * `maxCachedBytesPerPicture`: a larger picture is not put into the cache (object storage takes any accepted picture).
 */
return [
    'enabled' => true,
    'objectStorage' => 'agentToolImages',
    'objectKeyPrefix' => 'agent-tool-images/',
    'cacheTtlSeconds' => 86400,
    'maxCachedBytesPerPicture' => 2_097_152,
];
