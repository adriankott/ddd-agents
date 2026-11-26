<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services\Probes;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;

/** A package with one read tool that records the provenance each run received. */
class ProbeProvenanceMCPPackage extends MCPPackage
{
    /** @var AgentToolCallProvenance[] the provenance of each run, in order */
    public static array $provenancesOfRuns = [];

    public static function forgetRuns(): void
    {
        static::$provenancesOfRuns = [];
    }

    /** Records the provenance of the call. */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    public function probeRecordProvenance(ProbeNoteRequestDto $requestDto, AgentToolCallProvenance $callProvenance): string
    {
        static::$provenancesOfRuns[] = $callProvenance;
        return 'recorded';
    }
}
