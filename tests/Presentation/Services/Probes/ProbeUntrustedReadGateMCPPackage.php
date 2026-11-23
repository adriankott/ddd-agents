<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services\Probes;

use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolFetchesModelChosenUrl;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolRequiresOwnerConfirmation;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsTrustedContent;

/** Tools of every kind the gate after an untrusted read tells apart. */
class ProbeUntrustedReadGateMCPPackage extends MCPPackage
{
    /** Reads a record of the platform. */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolReturnsTrustedContent('probe')]
    public function probeReadPlatformRecord(): string
    {
        return 'record';
    }

    /** Reads something foreign. */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    public function probeReadForeignText(): string
    {
        return 'foreign';
    }

    /** Fetches a page. */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolFetchesModelChosenUrl('url', 'urls')]
    public function probeFetchPage(): string
    {
        return 'page';
    }

    /** Runs a shell command in the workspace. */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WORKSPACE)]
    public function probeRunWorkspaceCommand(): string
    {
        return 'ran';
    }

    /** Writes a record. */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    public function probeWriteRecord(): string
    {
        return 'written';
    }

    /** Deletes a record after its own consent step. */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::DELETE)]
    #[MCPToolRequiresOwnerConfirmation]
    public function probeDeleteRecordWithOwnConsentStep(): string
    {
        return 'deleted';
    }
}
