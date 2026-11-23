<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\AgentSandboxActionLogEntry;
use DDD\Domain\AI\Services\Sandboxes\AgentSandboxesAdminService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ConflictException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Untrusted\UntrustedContentHeaders;
use DDD\Tests\Agents\Domain\AI\Services\Sandboxes\Probes\FakeAgentSandboxAdministration;
use DDD\Tests\Agents\Domain\AI\Services\Sandboxes\Probes\ObservedAgentSandboxesAdminService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The package's side of the sandbox administration: the guard against a running turn (force needs a reason), the
 * audit of every action including refusals and failures, the reset confirmation, the content rules for workspace
 * files, and a host without an administration.
 */
class AgentSandboxesAdminServiceTest extends UnitTestCase
{
    public function testWithoutAnAdministrationTheListIsEmptyAndEveryOtherCallIsNotFound(): void
    {
        $agentSandboxesAdminService = new ObservedAgentSandboxesAdminService();
        $agentSandboxesAdminService->setAgentSandboxAdministrations([]);

        self::assertFalse($agentSandboxesAdminService->isConfigured());
        self::assertSame(0, $agentSandboxesAdminService->findWorkspaces()->count());
        $this->expectException(NotFoundException::class);
        $agentSandboxesAdminService->findWorkspace('ws-1');
    }

    public function testTheWorkspacesCarryTheConversationCountAndTheLiveTurn(): void
    {
        [$agentSandboxesAdminService] = $this->createService();
        $agentSandboxesAdminService->conversationCountsByAgentSpaceId = [31 => 4];
        $agentSandboxesAdminService->agentSpaceIdsWithALiveTurn = [31];

        $agentSandboxWorkspace = $agentSandboxesAdminService->findWorkspaces()->first();
        self::assertSame(4, $agentSandboxWorkspace->conversationCount);
        self::assertTrue($agentSandboxWorkspace->hasLiveTurn);
    }

    public function testAnActionWhileATurnRunsIsRefusedAndAudited(): void
    {
        [$agentSandboxesAdminService, $fakeAgentSandboxAdministration] = $this->createService();
        $agentSandboxesAdminService->agentSpaceIdsWithALiveTurn = [31];

        try {
            $agentSandboxesAdminService->runAction('ws-1', AgentSandboxActionLogEntry::ACTION_END, null, false, null, null);
            self::fail('a running turn refuses the action');
        } catch (ConflictException) {
        }
        self::assertSame([], $fakeAgentSandboxAdministration->receivedCalls);
        self::assertCount(1, $agentSandboxesAdminService->recordedActionLogEntries);
        self::assertSame(AgentSandboxActionLogEntry::OUTCOME_REFUSED, $agentSandboxesAdminService->recordedActionLogEntries[0]->outcome);
    }

    public function testAForcedActionNeedsAReasonAndThenRunsPastTheTurn(): void
    {
        [$agentSandboxesAdminService, $fakeAgentSandboxAdministration] = $this->createService();
        $agentSandboxesAdminService->agentSpaceIdsWithALiveTurn = [31];

        try {
            $agentSandboxesAdminService->runAction('ws-1', AgentSandboxActionLogEntry::ACTION_END, null, true, '  ', null);
            self::fail('force without a reason is refused');
        } catch (BadRequestException) {
        }
        self::assertSame(AgentSandboxActionLogEntry::OUTCOME_REFUSED, $agentSandboxesAdminService->recordedActionLogEntries[0]->outcome);

        $agentSandboxActionResult = $agentSandboxesAdminService->runAction('ws-1', AgentSandboxActionLogEntry::ACTION_END, null, true, 'session hangs', null);
        self::assertSame(AgentSandboxActionLogEntry::OUTCOME_SUCCEEDED, $agentSandboxActionResult->outcome);
        self::assertSame(['END:ws-1'], $fakeAgentSandboxAdministration->receivedCalls);
        $succeededEntry = $agentSandboxesAdminService->recordedActionLogEntries[1];
        self::assertTrue($succeededEntry->forced);
        self::assertSame('session hangs', $succeededEntry->reason);
        self::assertSame(31, $succeededEntry->agentSpaceId);
    }

    public function testAResetNeedsTheWorkspacesNameAsConfirmation(): void
    {
        [$agentSandboxesAdminService, $fakeAgentSandboxAdministration] = $this->createService();

        try {
            $agentSandboxesAdminService->runAction('ws-1', AgentSandboxActionLogEntry::ACTION_RESET, null, false, null, 'ws-1');
            self::fail('a reset confirmed with the key instead of the name is refused');
        } catch (BadRequestException) {
        }
        self::assertSame([], $fakeAgentSandboxAdministration->receivedCalls);

        $agentSandboxesAdminService->runAction('ws-1', AgentSandboxActionLogEntry::ACTION_RESET, null, false, null, 'Space 31');
        self::assertSame(['RESET:ws-1'], $fakeAgentSandboxAdministration->receivedCalls);
        self::assertSame(
            [AgentSandboxActionLogEntry::OUTCOME_REFUSED, AgentSandboxActionLogEntry::OUTCOME_SUCCEEDED],
            array_map(fn (AgentSandboxActionLogEntry $entry) => $entry->outcome, $agentSandboxesAdminService->recordedActionLogEntries)
        );
    }

    public function testAFailedHostCallIsAuditedAsFailedAndRethrown(): void
    {
        [$agentSandboxesAdminService] = $this->createService();

        try {
            $agentSandboxesAdminService->runAction('ws-1', AgentSandboxActionLogEntry::ACTION_KILL_JOB, 'missing', false, null, null);
            self::fail('the host\'s error reaches the caller');
        } catch (NotFoundException) {
        }
        $failedEntry = $agentSandboxesAdminService->recordedActionLogEntries[0];
        self::assertSame(AgentSandboxActionLogEntry::OUTCOME_FAILED, $failedEntry->outcome);
        self::assertSame('missing', $failedEntry->jobId);
    }

    public function testAnOffloadReportsWhatItCommitted(): void
    {
        [$agentSandboxesAdminService] = $this->createService();

        $agentSandboxActionResult = $agentSandboxesAdminService->runAction('ws-1', AgentSandboxActionLogEntry::ACTION_OFFLOAD, null, false, null, null);
        self::assertSame(3, $agentSandboxActionResult->offloadResult->committedChangeCount);
        self::assertStringContainsString('committed 3', (string)$agentSandboxesAdminService->recordedActionLogEntries[0]->outcomeDetail);
    }

    public function testFilesAreServedUnderTheUntrustedContentRules(): void
    {
        [$agentSandboxesAdminService, $fakeAgentSandboxAdministration] = $this->createService();
        $pngBytes = (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAMAAAACCAIAAAASFvFNAAAAC0lEQVR4nGNgwAQAABQAAX3+Hu4AAAAASUVORK5CYII=', true);
        $fakeAgentSandboxAdministration->fileBytesByPath = [
            'out/chart.png' => $pngBytes,
            'out/report.pdf' => "%PDF-1.4\n%fake",
            'notes/plan.md' => "# Plan\n<script>alert(1)</script>",
            'page.html' => "<html>\0<script>alert(1)</script>",
            'big.txt' => str_repeat('a', AgentSandboxesAdminService::MAX_TEXT_PREVIEW_BYTES + 10),
        ];

        $pngResponse = $agentSandboxesAdminService->buildFileContentResponse('ws-1', '/out/chart.png', true);
        self::assertSame('image/png', $pngResponse->headers->get('Content-Type'));
        self::assertStringStartsWith('inline', (string)$pngResponse->headers->get('Content-Disposition'));
        self::assertSame('out/chart.png', $fakeAgentSandboxAdministration->lastRequestedPath);

        $pdfResponse = $agentSandboxesAdminService->buildFileContentResponse('ws-1', 'out/report.pdf', true);
        self::assertSame('application/pdf', $pdfResponse->headers->get('Content-Type'));
        self::assertStringStartsWith('inline', (string)$pdfResponse->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $pdfResponse->headers->get('X-Content-Type-Options'));

        $textResponse = $agentSandboxesAdminService->buildFileContentResponse('ws-1', 'notes/plan.md', true);
        self::assertSame('text/plain; charset=utf-8', $textResponse->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment', (string)$textResponse->headers->get('Content-Disposition'));
        self::assertSame(UntrustedContentHeaders::CONTENT_SECURITY_POLICY, $textResponse->headers->get('Content-Security-Policy'));

        $binaryResponse = $agentSandboxesAdminService->buildFileContentResponse('ws-1', 'page.html', true);
        self::assertSame(UntrustedContentHeaders::OPAQUE_CONTENT_TYPE, $binaryResponse->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment', (string)$binaryResponse->headers->get('Content-Disposition'));

        $bigTextResponse = $agentSandboxesAdminService->buildFileContentResponse('ws-1', 'big.txt', true);
        self::assertSame('true', $bigTextResponse->headers->get(AgentSandboxesAdminService::TRUNCATED_HEADER));
        self::assertSame(AgentSandboxesAdminService::MAX_TEXT_PREVIEW_BYTES, strlen((string)$bigTextResponse->getContent()));
    }

    public function testAPathThatLeavesTheWorkspaceIsRefusedBeforeTheHostSeesIt(): void
    {
        [$agentSandboxesAdminService, $fakeAgentSandboxAdministration] = $this->createService();

        foreach (['../etc/passwd', 'out/../../x', 'out/%2e%2e/x', "out/\0x"] as $leavingPath) {
            try {
                $agentSandboxesAdminService->buildFileContentResponse('ws-1', $leavingPath, false);
                self::fail('refused: ' . $leavingPath);
            } catch (BadRequestException) {
            }
        }
        self::assertNull($fakeAgentSandboxAdministration->lastRequestedPath);
        $agentSandboxesAdminService->findFileEntries('ws-1', '/');
        self::assertSame('', $fakeAgentSandboxAdministration->lastRequestedPath);
    }

    /**
     * @return array{0: ObservedAgentSandboxesAdminService, 1: FakeAgentSandboxAdministration}
     */
    protected function createService(): array
    {
        $fakeAgentSandboxAdministration = new FakeAgentSandboxAdministration();
        $agentSandboxesAdminService = new ObservedAgentSandboxesAdminService();
        $agentSandboxesAdminService->setAgentSandboxAdministrations([$fakeAgentSandboxAdministration]);
        return [$agentSandboxesAdminService, $fakeAgentSandboxAdministration];
    }
}
