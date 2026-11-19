<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Tests\Agents\Presentation\Services\Probes\AdminAudienceAuditRecordingToolExecutor;
use DDD\Tests\Agents\Presentation\Services\Probes\ProbeAdminAudienceMCPPackage;
use DDD\Tests\Agents\Presentation\Services\Probes\ProbeReadAndWriteMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The audit of an admin-audience tool call receives the call's context — in the agent loop its provenance with the
 * conversation id, over HTTP none — and an application's override of the earlier audit point keeps being called.
 */
class MCPToolExecutorAdminAudienceAuditTest extends UnitTestCase
{
    public function testTheAuditOfAnAdminCallReceivesItsConversation(): void
    {
        $executor = new AdminAudienceAuditRecordingToolExecutor();

        $executor->callTool('probeListAccountsOfThePlatform', [], [ProbeAdminAudienceMCPPackage::class], null, false, new AgentToolCallProvenance(conversationId: 77));
        $executor->callTool('probeListAccountsOfThePlatform', [], [ProbeAdminAudienceMCPPackage::class]);

        self::assertSame([77, null], $executor->conversationIdsOfAuditedCalls, 'the loop\'s conversation, none over HTTP');
        self::assertSame(['probeListAccountsOfThePlatform', 'probeListAccountsOfThePlatform'], $executor->toolNamesRecordedByTheEarlierPoint);
    }

    public function testACallOutsideTheAdminAudienceIsNotAudited(): void
    {
        $executor = new AdminAudienceAuditRecordingToolExecutor();

        $executor->callTool('probeReadNoteUnderWriteLock', [], [ProbeReadAndWriteMCPPackage::class], null, false, new AgentToolCallProvenance(conversationId: 77));

        self::assertSame([], $executor->conversationIdsOfAuditedCalls);
        self::assertSame([], $executor->toolNamesRecordedByTheEarlierPoint);
    }
}
