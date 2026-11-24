<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallImageSpoolService;
use RuntimeException;

/** The spool with its store in memory: what a second worker invocation reads is what the first one wrote. */
class InMemoryAgentToolCallImageSpoolServiceDouble extends AgentToolCallImageSpoolService
{
    /** @var string[] The base64 texts by spool id */
    public array $spooledBase64TextsBySpoolImageId = [];

    public bool $spoolEnabled = true;

    public bool $writeFails = false;

    public function isSpoolEnabled(): bool
    {
        return $this->spoolEnabled;
    }

    protected function writeSpooledPicture(string $spoolImageId, ToolResultImage $toolResultImage): bool
    {
        if ($this->writeFails) {
            throw new RuntimeException('store unavailable');
        }
        $this->spooledBase64TextsBySpoolImageId[$spoolImageId] = $toolResultImage->base64Data;
        return true;
    }

    protected function readSpooledPicture(string $spoolImageId): ?string
    {
        return $this->spooledBase64TextsBySpoolImageId[$spoolImageId] ?? null;
    }
}
