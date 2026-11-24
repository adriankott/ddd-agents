<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Images;

use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The pictures one read of the picture store produced, by sha256, and where each came from: L1 (the process cache),
 * L2 (the shared cache), L3 (the object store) or nowhere. Never stored; the counts go into the call's request
 * snapshot.
 *
 * @phpstan-consistent-constructor
 */
class AgentToolCallImageReadResult extends ValueObject
{
    /** @var int Pictures found in L1 */
    public int $processCacheHits = 0;

    /** @var int Pictures found in L2 */
    public int $sharedCacheHits = 0;

    /** @var int Pictures found in L3 */
    public int $objectStoreHits = 0;

    /** @var int Pictures found nowhere (or whose bytes no longer match their reference) */
    public int $misses = 0;

    /** @var float The time of the read in milliseconds */
    public float $readMilliseconds = 0.0;

    /** @var array<string, ToolResultImage> the pictures by sha256 — an internal lookup, read through {@see findImageOfSha256()} */
    protected array $toolResultImagesBySha256 = [];

    public function addImage(ToolResultImage $toolResultImage): void
    {
        $this->toolResultImagesBySha256[$toolResultImage->sha256] = $toolResultImage;
    }

    public function findImageOfSha256(string $sha256): ?ToolResultImage
    {
        return $this->toolResultImagesBySha256[$sha256] ?? null;
    }

    public function countImages(): int
    {
        return count($this->toolResultImagesBySha256);
    }

    /** Adds the counts and the time of another read (one model call reads more than once). */
    public function addCountsOf(self $agentToolCallImageReadResult): void
    {
        $this->processCacheHits += $agentToolCallImageReadResult->processCacheHits;
        $this->sharedCacheHits += $agentToolCallImageReadResult->sharedCacheHits;
        $this->objectStoreHits += $agentToolCallImageReadResult->objectStoreHits;
        $this->misses += $agentToolCallImageReadResult->misses;
        $this->readMilliseconds += $agentToolCallImageReadResult->readMilliseconds;
    }
}
