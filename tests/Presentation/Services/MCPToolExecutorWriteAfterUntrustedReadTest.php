<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentReservation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentVerdict;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolContentTrust;
use DDD\Presentation\Mcp\Dtos\MCPToolResponse;
use DDD\Presentation\Mcp\Services\MCPToolExecutorService;
use DDD\Tests\Agents\Presentation\Services\Probes\ProbeContentTrustDeclaringResult;
use DDD\Tests\Agents\Presentation\Services\Probes\ProbeUntrustedReadGateMCPPackage;
use DDD\Tests\Agents\Presentation\Services\Probes\UntrustedReadGateToolExecutor;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;

/**
 * The executor's gate after an untrusted read of the current owner turn
 * ({@see MCPToolExecutorService::holdCallAfterUntrustedRead()}): a write waits for the owner's consent or is refused,
 * reads and workspace calls pass, a tool with its own consent step is left to it, a fetch of an unseen model-built
 * URL is refused; nothing is held without an untrusted read in the turn or outside an agent call.
 */
class MCPToolExecutorWriteAfterUntrustedReadTest extends UnitTestCase
{
    public function testAWriteAfterAnUntrustedReadNeedsTheOwnersConsent(): void
    {
        $request = OwnerConsentRequest::forAction('probeWriteRecord', 'WRITE_AFTER_UNTRUSTED_READ', 'target', 'consequence', 'key');
        $executor = new UntrustedReadGateToolExecutor();
        $executor->givenOwnerConsentReservation = OwnerConsentReservation::notReserved($request, OwnerConsentVerdict::failed(OwnerConsentVerdict::FAILURE_SITE_OFF));
        $refusal = $this->holdCall('probeWriteRecord', [], $this->provenanceAfterReading('probeReadForeignText'), $executor);

        self::assertNotNull($refusal);
        self::assertSame(MCPToolResponse::REFUSAL_OWNER_CONFIRMATION_REQUIRED, $refusal->refusalKind);
        self::assertStringContainsString('probeReadForeignText', $refusal->content[0]['text']);

        $executor->givenOwnerConsentReservation = OwnerConsentReservation::ungated($request, OwnerConsentVerdict::ungated());
        self::assertNull($this->holdCall('probeWriteRecord', [], $this->provenanceAfterReading('probeReadForeignText'), $executor), 'the owner\'s yes lets the write run');
    }

    public function testUnderRefuseAWriteAfterAnUntrustedReadIsRefusedForTheTurn(): void
    {
        $executor = new UntrustedReadGateToolExecutor();
        $executor->writeAfterUntrustedReadMode = MCPToolContentTrust::WRITE_AFTER_UNTRUSTED_READ_REFUSE;
        $refusal = $this->holdCall('probeWriteRecord', [], $this->provenanceAfterReading('probeReadForeignText'), $executor);

        self::assertNotNull($refusal);
        self::assertTrue($refusal->isFinalRefusal());
        self::assertSame(MCPToolResponse::REFUSAL_WRITE_AFTER_UNTRUSTED_READ, $refusal->refusalKind);
    }

    public function testUnderOffAWriteAfterAnUntrustedReadRunsAlsoInADelegationChild(): void
    {
        $executor = new UntrustedReadGateToolExecutor();
        $executor->writeAfterUntrustedReadMode = MCPToolContentTrust::WRITE_AFTER_UNTRUSTED_READ_OFF;
        // the consent step refuses (as it does for a delegated sub-task or a scheduled run): under off it is never asked
        $request = OwnerConsentRequest::forAction('probeWriteRecord', 'WRITE_AFTER_UNTRUSTED_READ', 'target', 'consequence', 'key');
        $executor->givenOwnerConsentReservation = OwnerConsentReservation::notReserved($request, OwnerConsentVerdict::failed(OwnerConsentVerdict::FAILURE_SITE_OFF));

        self::assertNull($this->holdCall('probeWriteRecord', [], $this->provenanceAfterReading('probeReadForeignText'), $executor), 'no refusal, also where consent cannot be asked');
        self::assertNull(self::getPropertyValue($executor, 'untrustedReadConsentReservation'), 'no consent reserved');
    }

    public function testTheUnseenUrlRefusalHasItsOwnKey(): void
    {
        $executor = new UntrustedReadGateToolExecutor();
        $executor->writeAfterUntrustedReadMode = MCPToolContentTrust::WRITE_AFTER_UNTRUSTED_READ_OFF;
        $provenance = $this->provenanceAfterReading('probeReadForeignText');
        self::assertSame(
            MCPToolResponse::REFUSAL_UNSEEN_URL_AFTER_UNTRUSTED_READ,
            $this->holdCall('probeFetchPage', ['url' => 'https://collector.example.net/?d=secret'], $provenance, $executor)?->refusalKind,
            'refused with the write gate off'
        );
        $executor->unseenUrlAfterUntrustedReadMode = MCPToolContentTrust::UNSEEN_URL_AFTER_UNTRUSTED_READ_OFF;
        self::assertNull($this->holdCall('probeFetchPage', ['url' => 'https://collector.example.net/?d=secret'], $provenance, $executor));

        $consentExecutor = new UntrustedReadGateToolExecutor();
        $consentExecutor->unseenUrlAfterUntrustedReadMode = MCPToolContentTrust::UNSEEN_URL_AFTER_UNTRUSTED_READ_OFF;
        $consentExecutor->givenOwnerConsentReservation = OwnerConsentReservation::notReserved(
            OwnerConsentRequest::forAction('probeWriteRecord', 'WRITE_AFTER_UNTRUSTED_READ', 'target', 'consequence', 'key'),
            OwnerConsentVerdict::failed(OwnerConsentVerdict::FAILURE_SITE_OFF)
        );
        self::assertSame(
            MCPToolResponse::REFUSAL_OWNER_CONFIRMATION_REQUIRED,
            $this->holdCall('probeWriteRecord', [], $provenance, $consentExecutor)?->refusalKind,
            'the URL key leaves the write gate as configured'
        );
    }

    public function testReadsWorkspaceCallsAndOwnConsentStepsPass(): void
    {
        $provenance = $this->provenanceAfterReading('probeReadForeignText');
        foreach (['probeReadPlatformRecord', 'probeReadForeignText', 'probeRunWorkspaceCommand', 'probeDeleteRecordWithOwnConsentStep'] as $toolName) {
            self::assertNull($this->holdCall($toolName, [], $provenance), $toolName);
        }
    }

    public function testTheMandateClassReachesTheConsentStepUnlessWritesAfterAnUntrustedReadAreHeld(): void
    {
        $packageReflectionClass = new ReflectionClass(ProbeUntrustedReadGateMCPPackage::class);
        $offExecutor = new UntrustedReadGateToolExecutor();
        $offExecutor->writeAfterUntrustedReadMode = MCPToolContentTrust::WRITE_AFTER_UNTRUSTED_READ_OFF;
        $provenance = $this->provenanceAfterReading('probeReadForeignText');
        self::invokeMethod($offExecutor, 'passOwnerConsentCoveringMandateActionClass', [$packageReflectionClass->getMethod('probeSendReplyCoveredByMandate'), $provenance]);
        self::assertSame('EXAMPLE_REPLY', $provenance->ownerConsentCoveringMandateActionClass);

        self::invokeMethod($offExecutor, 'passOwnerConsentCoveringMandateActionClass', [$packageReflectionClass->getMethod('probeDeleteRecordWithOwnConsentStep'), $provenance]);
        self::assertNull($provenance->ownerConsentCoveringMandateActionClass, 'a tool without a covering class resets it');

        $heldExecutor = new UntrustedReadGateToolExecutor();
        self::invokeMethod($heldExecutor, 'passOwnerConsentCoveringMandateActionClass', [$packageReflectionClass->getMethod('probeSendReplyCoveredByMandate'), $provenance]);
        self::assertNull($provenance->ownerConsentCoveringMandateActionClass, 'after an untrusted read the owner confirms themselves');

        $cleanProvenance = $this->provenanceAfterReading('probeReadPlatformRecord');
        self::invokeMethod($heldExecutor, 'passOwnerConsentCoveringMandateActionClass', [$packageReflectionClass->getMethod('probeSendReplyCoveredByMandate'), $cleanProvenance]);
        self::assertSame('EXAMPLE_REPLY', $cleanProvenance->ownerConsentCoveringMandateActionClass);
    }

    public function testNothingIsHeldWithoutAnUntrustedReadInTheTurnOrOutsideAnAgentCall(): void
    {
        self::assertNull($this->holdCall('probeWriteRecord', [], $this->provenanceAfterReading('probeReadPlatformRecord')));
        self::assertNull($this->holdCall('probeWriteRecord', [], $this->provenanceAfterReading('probeReadForeignText', readBeforeTheTurn: true)));
        self::assertNull($this->holdCall('probeWriteRecord', [], null));
        $harnessProvenance = $this->provenanceAfterReading('probeReadForeignText');
        $harnessProvenance->isEvalHarnessCall = true;
        self::assertNull($this->holdCall('probeWriteRecord', [], $harnessProvenance));
    }

    public function testAFetchOfAnUnseenModelBuiltUrlIsRefused(): void
    {
        $provenance = $this->provenanceAfterReading('probeReadForeignText');

        $refusal = $this->holdCall('probeFetchPage', ['url' => 'https://collector.example.net/?d=secret'], $provenance);
        self::assertNotNull($refusal);
        self::assertSame(MCPToolResponse::REFUSAL_UNSEEN_URL_AFTER_UNTRUSTED_READ, $refusal->refusalKind);

        $listRefusal = $this->holdCall('probeFetchPage', ['urls' => ['https://shown.example.org/page?id=7', 'https://collector.example.net/#x']], $provenance);
        self::assertSame(MCPToolResponse::REFUSAL_UNSEEN_URL_AFTER_UNTRUSTED_READ, $listRefusal?->refusalKind);

        self::assertNull($this->holdCall('probeFetchPage', ['url' => 'https://shown.example.org/page?id=7'], $provenance), 'a link exactly as a result showed it');
        self::assertNull($this->holdCall('probeFetchPage', ['url' => 'https://other.example.org/plain'], $provenance), 'no query string, no fragment');
    }

    public function testTheCallsOwnVerdictWinsOverTheToolsRule(): void
    {
        self::assertNull(
            $this->holdCall('probeWriteRecord', [], $this->provenanceAfterReading('probeReadForeignText', returnedUntrustedContent: false)),
            'a foreign-text tool whose result declared only platform text is a trusted read'
        );
        $refusingExecutor = new UntrustedReadGateToolExecutor();
        $refusingExecutor->writeAfterUntrustedReadMode = MCPToolContentTrust::WRITE_AFTER_UNTRUSTED_READ_REFUSE;
        self::assertSame(
            MCPToolResponse::REFUSAL_WRITE_AFTER_UNTRUSTED_READ,
            $this->holdCall('probeWriteRecord', [], $this->provenanceAfterReading('probeReadPlatformRecord', returnedUntrustedContent: true), $refusingExecutor)?->refusalKind,
            'a trusted tool whose result declared third-party text is an untrusted read'
        );
        self::assertNull(
            $this->holdCall('probeWriteRecord', [], $this->provenanceAfterReading('probeReadForeignText', returnedUntrustedContent: false), $refusingExecutor),
            'also under refuse'
        );
    }

    public function testAResultDeclaresItsTrustAndOneThatCannotDecideIsUntrusted(): void
    {
        $executor = new UntrustedReadGateToolExecutor();
        foreach ([[true, true], [false, false], [null, false]] as [$holdsOnlyPlatformText, $expectedTrust]) {
            $response = self::invokeMethod($executor, 'applyResultNoteAndDictatedWidgetType', [
                new ProbeContentTrustDeclaringResult($holdsOnlyPlatformText),
                new MCPToolResponse('result'),
            ]);
            self::assertSame($expectedTrust, $response->getDeclaredTrustedContent());
        }
        $undeclaredResponse = self::invokeMethod($executor, 'applyResultNoteAndDictatedWidgetType', ['plain', new MCPToolResponse('result')]);
        self::assertNull($undeclaredResponse->getDeclaredTrustedContent(), 'no declaration: the tool\'s static rule applies');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function holdCall(string $toolName, array $arguments, ?AgentToolCallProvenance $provenance, ?UntrustedReadGateToolExecutor $executor = null): ?MCPToolResponse
    {
        $executor ??= new UntrustedReadGateToolExecutor();
        $packageReflectionClass = new ReflectionClass(ProbeUntrustedReadGateMCPPackage::class);
        return self::invokeMethod($executor, 'holdCallAfterUntrustedRead', [
            $toolName,
            $packageReflectionClass,
            $packageReflectionClass->getMethod($toolName),
            $arguments,
            $provenance,
        ]);
    }

    protected function provenanceAfterReading(string $readToolName, bool $readBeforeTheTurn = false, ?bool $returnedUntrustedContent = null): AgentToolCallProvenance
    {
        $readRow = $this->toolCallRow($readBeforeTheTurn ? 0 : 2, $readToolName, 'see https://shown.example.org/page?id=7');
        $readRow->toolCall->returnedUntrustedContent = $returnedUntrustedContent;
        $ownerRow = $this->row(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER, 'look this up', 1);
        $messages = new AIConversationMessages();
        foreach ($readBeforeTheTurn ? [$readRow, $ownerRow] : [$ownerRow, $readRow] as $messageRow) {
            $messages->add($messageRow);
        }
        /** @var AIConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(AIConversation::class);
        $conversation->messages = $messages;
        return new AgentToolCallProvenance(sourceUserMessage: $ownerRow, conversationMessages: $messages, conversation: $conversation);
    }

    protected function toolCallRow(int $index, string $toolName, string $result): AIConversationMessage
    {
        $message = $this->row(AIConversationMessage::ROLE_ASSISTANT, AIConversationMessage::AUTHOR_ASSISTANT, '', $index);
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $toolCall = new ToolCall();
        $toolCall->toolCallId = 'call_' . $index;
        $toolCall->name = $toolName;
        $toolCall->arguments = [];
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $toolCall->result = $result;
        $message->toolCall = $toolCall;
        return $message;
    }

    protected function row(string $role, string $authorType, string $text, int $index): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = $role;
        $message->authorType = $authorType;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = $text;
        $message->indexInConversation = $index;
        return $message;
    }
}
