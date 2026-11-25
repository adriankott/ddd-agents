<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Mcp\Client\Voice;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackageDocumentation;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEntitlement;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolOverlays;
use DDD\Domain\AI\Entities\Voice\AgentVoiceAccountRights;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallEnd;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallStart;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallEndsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\Voice\AgentVoiceCallsMCPPackage;
use DDD\Presentation\Mcp\Client\Voice\Dtos\AgentVoiceCallEndRequestDto;
use DDD\Presentation\Mcp\Client\Voice\Dtos\AgentVoiceCallsGetRequestDto;
use DDD\Presentation\Mcp\Client\Voice\Dtos\AgentVoiceCallStartRequestDto;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;
use ReflectionClass;
use RuntimeException;

/**
 * The agent's three voice tools (interfaces, section 7): offered only while the voice channel is on, behind the right
 * `useVoice`, with the contract's tool and argument names. `startVoiceCall` starts a call for the signed-in account on
 * the conversation it runs in (its root) and answers the ring or the refusal sentence; `endVoiceCall` ends a call of
 * the conversation's account only, a call id of another account is refused; `getVoiceCalls` reads the calls of that
 * account, the running ones or the log under the request's query options, which are put back after the read.
 */
class AgentVoiceCallsMCPPackageTest extends AgentVoiceIntegrationTestCase
{
    public const int ACCOUNT_ID = 12;

    public const int CONVERSATION_ID = 40;

    public const int ROOT_CONVERSATION_ID = 39;

    public const int CALL_ID = 7;

    /** @var array<int, int|null> The `top` of the call set's default query options at each history read */
    protected array $topDuringHistoryReads = [];

    /** @var array<int, string> The order of the call set's default query options at each history read */
    protected array $orderByDuringHistoryReads = [];

    protected Account $signedInAccount;

    /** @var AgentVoiceCalls|null The call log the history read answered */
    protected ?AgentVoiceCalls $callLogOfHistoryRead = null;

    protected function setUp(): void
    {
        parent::setUp();
        MCPToolOverlays::replaceCurrent(MCPToolOverlays::fromConfigEntries([]));
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true]));
        $this->declareAgentPersonaConfiguration(['agentName' => '']);
        $this->signedInAccount = new Account();
        $this->signedInAccount->id = static::ACCOUNT_ID;
        AuthService::instance()->setAccount($this->signedInAccount);
    }

    protected function onTearDown(): void
    {
        MCPToolOverlays::resetCurrent();
        parent::onTearDown();
    }

    public function testThePackageIsAvailableOnlyWhileTheVoiceChannelIsOn(): void
    {
        static::assertTrue(AgentVoiceCallsMCPPackage::isAvailable());
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => false]));
        static::assertFalse(AgentVoiceCallsMCPPackage::isAvailable());
    }

    public function testEveryToolRequiresTheRightUseVoiceThroughThePackagesAttribute(): void
    {
        static::assertSame('useVoice', AgentVoiceAccountRights::USE_VOICE);
        static::assertSame([AgentVoiceAccountRights::USE_VOICE], MCPPackage::findRequiredAccountRightNamesOfClass(AgentVoiceCallsMCPPackage::class));
        $reflectionClass = new \ReflectionClass(AgentVoiceCallsMCPPackage::class);
        foreach (['startVoiceCall', 'endVoiceCall', 'getVoiceCalls'] as $toolName) {
            static::assertSame(
                [AgentVoiceAccountRights::USE_VOICE],
                MCPToolEntitlement::createFromReflectionMethod($reflectionClass, $reflectionClass->getMethod($toolName))->requiredAccountRightNames,
                $toolName
            );
        }
    }

    public function testASubclassKeepsTheRightOfThePackageItExtends(): void
    {
        static::assertSame([AgentVoiceAccountRights::USE_VOICE], MCPPackage::findRequiredAccountRightNamesOfClass(SubclassedAgentVoiceCallsMCPPackage::class));
        $reflectionClass = new ReflectionClass(SubclassedAgentVoiceCallsMCPPackage::class);
        static::assertSame(
            [AgentVoiceAccountRights::USE_VOICE],
            MCPToolEntitlement::createFromReflectionMethod($reflectionClass, $reflectionClass->getMethod('startVoiceCall'))->requiredAccountRightNames,
            'the gate of the tool is not lost by subclassing'
        );
    }

    public function testTheToolsAndTheirArgumentsAreTheContracts(): void
    {
        $packageDocumentation = MCPPackageDocumentation::createFromClassName(AgentVoiceCallsMCPPackage::class);

        $argumentNamesByToolName = [];
        foreach ($packageDocumentation->functions as $toolDocumentation) {
            static::assertNotSame('', trim((string)$toolDocumentation->description), $toolDocumentation->name);
            $inputSchema = json_decode((string)json_encode($toolDocumentation->inputSchema->toObject(forPersistence: false)), true);
            $argumentNamesByToolName[$toolDocumentation->name] = array_keys($inputSchema['properties'] ?? []);
        }

        static::assertSame('agent.voice', $packageDocumentation->name);
        static::assertSame(['startVoiceCall', 'getVoiceCalls', 'endVoiceCall'], array_keys($argumentNamesByToolName));
        static::assertSame(['task', 'conversation', 'purposeName'], $argumentNamesByToolName['startVoiceCall']);
        static::assertSame(['callId'], $argumentNamesByToolName['endVoiceCall']);
        static::assertSame('onlyRunning', $argumentNamesByToolName['getVoiceCalls'][0], 'the one switch, then the query options of the call log');
        static::assertSame([AgentVoiceCallStart::CONVERSATION_CURRENT, AgentVoiceCallStart::CONVERSATION_NEW], AgentVoiceCallStart::REQUESTABLE_CONVERSATIONS);
    }

    public function testAStartedCallRingsForTheSignedInAccountOnTheConversationOfTheToolCall(): void
    {
        $agentSpace = new AgentSpace();
        $agentSpace->id = 3;
        $aiConversation = $this->buildConversation(static::CONVERSATION_ID);
        $callProvenance = new AgentToolCallProvenance(conversationId: static::CONVERSATION_ID, agentSpace: $agentSpace, conversation: $aiConversation);
        $startedCall = $this->buildCall();
        $agentVoiceCallStartsService = $this->createMock(AgentVoiceCallStartsService::class);
        $agentVoiceCallStartsService->expects(static::once())->method('startVoiceCall')
            ->with(static::identicalTo($this->signedInAccount), static::identicalTo($agentSpace), static::identicalTo($aiConversation), false, 'Go through the open points.', null)
            ->willReturn(AgentVoiceCallStart::forAppRinging($startedCall));
        $startRequestDto = new AgentVoiceCallStartRequestDto();
        $startRequestDto->task = '  Go through the open points.  ';
        $startRequestDto->purposeName = '   ';

        $responseDto = (new AgentVoiceCallsMCPPackage())->startVoiceCall($startRequestDto, $agentVoiceCallStartsService, $callProvenance);

        static::assertSame(AgentVoiceCallStart::CHANNEL_APP, $responseDto->channel);
        static::assertSame(static::CALL_ID, $responseDto->callId);
        static::assertSame(static::CONVERSATION_ID, $responseDto->conversationId);
        static::assertSame(90, $responseDto->ringSeconds);
        static::assertSame(
            'The app is ringing now on every open client of the owner, for 90 seconds. Tell the owner right away that the app is ringing. '
            . 'If nobody answers, the call ends unanswered and a call-end note appears in this conversation.',
            $responseDto->note
        );
    }

    public function testACallInANewConversationWithAPurposeHandsBothToTheCallStart(): void
    {
        $callProvenance = new AgentToolCallProvenance(conversationId: static::CONVERSATION_ID, conversation: $this->buildConversation(static::CONVERSATION_ID));
        $agentVoiceCallStartsService = $this->createMock(AgentVoiceCallStartsService::class);
        $agentVoiceCallStartsService->expects(static::once())->method('startVoiceCall')
            ->with(static::identicalTo($this->signedInAccount), null, static::isInstanceOf(AIConversation::class), true, null, 'FirstSteps')
            ->willReturn(AgentVoiceCallStart::forAppRinging($this->buildCall()));
        $startRequestDto = new AgentVoiceCallStartRequestDto();
        $startRequestDto->conversation = AgentVoiceCallStart::CONVERSATION_NEW;
        $startRequestDto->purposeName = ' FirstSteps ';

        (new AgentVoiceCallsMCPPackage())->startVoiceCall($startRequestDto, $agentVoiceCallStartsService, $callProvenance);
    }

    public function testTheCallOfADelegatedTaskContinuesTheRootConversation(): void
    {
        $rootConversation = $this->buildConversation(static::ROOT_CONVERSATION_ID);
        $aIConversationsService = $this->createMock(AIConversationsService::class);
        $aIConversationsService->expects(static::once())->method('find')->with(static::ROOT_CONVERSATION_ID)->willReturn($rootConversation);
        $this->replaceService(AIConversationsService::class, $aIConversationsService);
        $callProvenance = new AgentToolCallProvenance(
            conversationId: static::CONVERSATION_ID,
            rootConversationId: static::ROOT_CONVERSATION_ID,
            conversation: $this->buildConversation(static::CONVERSATION_ID)
        );
        $agentVoiceCallStartsService = $this->createMock(AgentVoiceCallStartsService::class);
        $agentVoiceCallStartsService->expects(static::once())->method('startVoiceCall')
            ->with(static::identicalTo($this->signedInAccount), null, static::identicalTo($rootConversation), false, null, null)
            ->willReturn(AgentVoiceCallStart::forAppRinging($this->buildCall()));

        (new AgentVoiceCallsMCPPackage())->startVoiceCall(new AgentVoiceCallStartRequestDto(), $agentVoiceCallStartsService, $callProvenance);
    }

    public function testARefusedCallStartIsAnsweredWithItsSentence(): void
    {
        $agentVoiceCallStartsService = $this->createMock(AgentVoiceCallStartsService::class);
        $agentVoiceCallStartsService->method('startVoiceCall')->willReturn(AgentVoiceCallStart::forRefused(AgentVoiceCallStart::REFUSED_ALREADY_ON_A_CALL, true));

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('No call: the owner is on a call with the agent right now. Work the request in that call, or offer a schedule that calls back later.');
        (new AgentVoiceCallsMCPPackage())->startVoiceCall(new AgentVoiceCallStartRequestDto(), $agentVoiceCallStartsService, new AgentToolCallProvenance());
    }

    public function testACallStartWithoutASignedInAccountIsRefusedBeforeTheCallStart(): void
    {
        $noAccount = null;
        AuthService::instance()->setAccount($noAccount);
        $agentVoiceCallStartsService = $this->createMock(AgentVoiceCallStartsService::class);
        $agentVoiceCallStartsService->expects(static::never())->method(static::anything());

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('No authenticated account');
        (new AgentVoiceCallsMCPPackage())->startVoiceCall(new AgentVoiceCallStartRequestDto(), $agentVoiceCallStartsService, new AgentToolCallProvenance());
    }

    public function testAnEndIsAskedForTheAccountAndTheRootConversationOfTheToolCall(): void
    {
        $endedCall = $this->buildCall();
        $endedCall->startedAt = DateTime::fromTimestamp(time() - 60);
        $agentVoiceCallEndsService = $this->createMock(AgentVoiceCallEndsService::class);
        $agentVoiceCallEndsService->expects(static::once())->method('endVoiceCall')->with(static::ACCOUNT_ID, static::ROOT_CONVERSATION_ID, null)
            ->willReturn(AgentVoiceCallEnd::forEnded(AgentVoiceCallEnd::OUTCOME_ENDS_AFTER_GOODBYE, $endedCall, AgentVoiceCall::CALL_STATE_IN_PROGRESS));
        $callProvenance = new AgentToolCallProvenance(conversationId: static::CONVERSATION_ID, rootConversationId: static::ROOT_CONVERSATION_ID, conversation: $this->buildConversation(static::CONVERSATION_ID));

        $responseDto = (new AgentVoiceCallsMCPPackage())->endVoiceCall(new AgentVoiceCallEndRequestDto(), $agentVoiceCallEndsService, $callProvenance);

        static::assertSame(static::CALL_ID, $responseDto->callId);
        static::assertSame(AgentVoiceCallEnd::OUTCOME_ENDS_AFTER_GOODBYE, $responseDto->outcome);
        static::assertSame(
            'The call you are on ends right after this answer is spoken. Answer with ONE short goodbye sentence in the language of the call: no question, no further offer.',
            $responseDto->note
        );
    }

    public function testAnEndOfARingingCallSaysItWasWithdrawn(): void
    {
        $agentVoiceCallEndsService = $this->createMock(AgentVoiceCallEndsService::class);
        $agentVoiceCallEndsService->expects(static::once())->method('endVoiceCall')->with(static::ACCOUNT_ID, static::CONVERSATION_ID, static::CALL_ID)
            ->willReturn(AgentVoiceCallEnd::forEnded(AgentVoiceCallEnd::OUTCOME_ENDED_NOW, $this->buildCall(), AgentVoiceCall::CALL_STATE_RINGING));
        $endRequestDto = new AgentVoiceCallEndRequestDto();
        $endRequestDto->callId = static::CALL_ID;

        $responseDto = (new AgentVoiceCallsMCPPackage())->endVoiceCall($endRequestDto, $agentVoiceCallEndsService, new AgentToolCallProvenance(conversationId: static::CONVERSATION_ID));

        static::assertSame(AgentVoiceCallEnd::OUTCOME_ENDED_NOW, $responseDto->outcome);
        static::assertSame('Call #7 was still ringing and is withdrawn: it no longer rings. Tell the owner in one sentence.', $responseDto->note);
    }

    public function testAnEndWithTheCallIdOfAnotherAccountIsRefused(): void
    {
        $agentVoiceCallsService = $this->createMock(AgentVoiceCallsService::class);
        $agentVoiceCallsService->expects(static::once())->method('findOpenCallsOfAccountId')->with(static::ACCOUNT_ID)->willReturn(new AgentVoiceCalls());
        $agentVoiceCallsService->expects(static::once())->method('findCallOfAccountIdById')->with(8815, static::ACCOUNT_ID)->willReturn(null);
        $agentVoiceCallsService->expects(static::never())->method('endCallNow');
        $this->replaceService(AgentVoiceCallsService::class, $agentVoiceCallsService);
        /** @var AgentVoiceCallEndsService $agentVoiceCallEndsService */
        $agentVoiceCallEndsService = DDDService::instance()->getService(AgentVoiceCallEndsService::class);
        $endRequestDto = new AgentVoiceCallEndRequestDto();
        $endRequestDto->callId = 8815;

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('No call ended: there is no such call of this account. No call of this account is ringing or running right now.');
        (new AgentVoiceCallsMCPPackage())->endVoiceCall($endRequestDto, $agentVoiceCallEndsService, new AgentToolCallProvenance(conversationId: static::CONVERSATION_ID, conversation: $this->buildConversation(static::CONVERSATION_ID)));
    }

    public function testARefusedEndNamesTheCallsThatRingOrRun(): void
    {
        $agentVoiceCallEndsService = $this->createMock(AgentVoiceCallEndsService::class);
        $agentVoiceCallEndsService->method('endVoiceCall')->willReturn(AgentVoiceCallEnd::forRefused(AgentVoiceCallEnd::REFUSED_CALL_NOT_FOUND, null, [8, 9]));

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('No call ended: there is no such call of this account. Calls ringing or running right now: #8, #9. Pass the callId of the one the owner means (getVoiceCalls with onlyRunning shows them).');
        (new AgentVoiceCallsMCPPackage())->endVoiceCall(new AgentVoiceCallEndRequestDto(), $agentVoiceCallEndsService, new AgentToolCallProvenance());
    }

    public function testAToolCallWithoutAnyAccountIsRefused(): void
    {
        $noAccount = null;
        AuthService::instance()->setAccount($noAccount);
        $agentVoiceCallsService = $this->createMock(AgentVoiceCallsService::class);
        $agentVoiceCallsService->expects(static::never())->method(static::anything());

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('This call has no account: the voice calls are read for the owner of the conversation.');
        (new AgentVoiceCallsMCPPackage())->getVoiceCalls(new AgentVoiceCallsGetRequestDto(), $agentVoiceCallsService, new AgentToolCallProvenance());
    }

    public function testTheRunningCallsAreTheOpenCallsOfTheSignedInAccountNow(): void
    {
        $runningCall = $this->buildCall();
        $runningCall->startedAt = DateTime::fromTimestamp(time() - 60);
        $overdueRing = $this->buildCall();
        $overdueRing->id = static::CALL_ID + 1;
        $overdueRing->ringDeadlineAt = DateTime::fromTimestamp(time() - 10);
        $openCallsOfAccount = new AgentVoiceCalls();
        $openCallsOfAccount->add($runningCall, $overdueRing);
        $agentVoiceCallsService = $this->createMock(AgentVoiceCallsService::class);
        $agentVoiceCallsService->expects(static::once())->method('findOpenCallsOfAccountId')->with(static::ACCOUNT_ID)->willReturn($openCallsOfAccount);
        $agentVoiceCallsService->expects(static::never())->method('findCallsOfAccountId');
        $getRequestDto = new AgentVoiceCallsGetRequestDto();
        $getRequestDto->onlyRunning = true;

        $responseDto = (new AgentVoiceCallsMCPPackage())->getVoiceCalls($getRequestDto, $agentVoiceCallsService, new AgentToolCallProvenance(conversationId: static::CONVERSATION_ID));

        static::assertTrue($responseDto->onlyRunning);
        static::assertSame(static::CONVERSATION_ID, $responseDto->currentConversationId);
        static::assertSame([$runningCall], $responseDto->voiceCalls->getElements());
    }

    public function testTheCallLogIsReadUnderTheRequestsQueryOptionsAndTheOptionsArePutBack(): void
    {
        $topBefore = AgentVoiceCalls::getDefaultQueryOptions()->getTop();
        $orderByBefore = static::describeOrderBy(AgentVoiceCalls::getDefaultQueryOptions()->getOrderBy());
        $agentVoiceCallsService = $this->createMock(AgentVoiceCallsService::class);
        $agentVoiceCallsService->expects(static::once())->method('findCallsOfAccountId')->with(static::ACCOUNT_ID)
            ->willReturnCallback([$this, 'answerHistoryRead']);
        $agentVoiceCallsService->expects(static::never())->method('findOpenCallsOfAccountId');
        $getRequestDto = new AgentVoiceCallsGetRequestDto();
        $getRequestDto->top = 5;

        $responseDto = (new AgentVoiceCallsMCPPackage())->getVoiceCalls($getRequestDto, $agentVoiceCallsService, new AgentToolCallProvenance(conversationId: static::CONVERSATION_ID, rootConversationId: static::ROOT_CONVERSATION_ID));

        static::assertSame([5], $this->topDuringHistoryReads);
        static::assertSame(['id DESC'], $this->orderByDuringHistoryReads, 'newest first when the request asks for no order');
        static::assertSame($this->callLogOfHistoryRead, $responseDto->voiceCalls);
        static::assertSame(static::ROOT_CONVERSATION_ID, $responseDto->currentConversationId);
        static::assertSame($topBefore, AgentVoiceCalls::getDefaultQueryOptions()->getTop(), 'the options are put back after the read');
        static::assertSame($orderByBefore, static::describeOrderBy(AgentVoiceCalls::getDefaultQueryOptions()->getOrderBy()));
    }

    public function testTheOrderTheRequestAsksForWinsAndIsPutBackAfterAFailedRead(): void
    {
        $topBefore = AgentVoiceCalls::getDefaultQueryOptions()->getTop();
        $agentVoiceCallsService = $this->createMock(AgentVoiceCallsService::class);
        $agentVoiceCallsService->expects(static::once())->method('findCallsOfAccountId')->willReturnCallback([$this, 'failHistoryRead']);
        $getRequestDto = new AgentVoiceCallsGetRequestDto();
        $getRequestDto->top = 3;
        $getRequestDto->orderBy = OrderByOptions::fromString('startedAt asc');

        try {
            (new AgentVoiceCallsMCPPackage())->getVoiceCalls($getRequestDto, $agentVoiceCallsService, new AgentToolCallProvenance());
            static::fail('a failed read must reach the caller');
        } catch (RuntimeException $readFailure) {
            static::assertSame('the call log is gone', $readFailure->getMessage());
        }
        static::assertSame([3], $this->topDuringHistoryReads);
        static::assertSame(['startedAt ASC'], $this->orderByDuringHistoryReads);
        static::assertSame($topBefore, AgentVoiceCalls::getDefaultQueryOptions()->getTop(), 'the options are put back after a failed read');
    }

    /** The call log's answer: records the default query options the read ran under. */
    public function answerHistoryRead(int $accountId): AgentVoiceCalls
    {
        $this->recordQueryOptionsOfHistoryRead();
        $this->callLogOfHistoryRead = new AgentVoiceCalls();

        return $this->callLogOfHistoryRead;
    }

    /** @throws RuntimeException always, after recording the default query options the read ran under */
    public function failHistoryRead(int $accountId): AgentVoiceCalls
    {
        $this->recordQueryOptionsOfHistoryRead();
        throw new RuntimeException('the call log is gone');
    }

    protected function recordQueryOptionsOfHistoryRead(): void
    {
        $this->topDuringHistoryReads[] = AgentVoiceCalls::getDefaultQueryOptions()->getTop();
        $this->orderByDuringHistoryReads[] = static::describeOrderBy(AgentVoiceCalls::getDefaultQueryOptions()->getOrderBy());
    }

    /** An order as `property DIRECTION`, comma-separated; '' for none. */
    protected static function describeOrderBy(?OrderByOptions $orderByOptions): string
    {
        $orderParts = [];
        foreach ($orderByOptions?->getElements() ?? [] as $orderByOption) {
            $orderParts[] = $orderByOption->propertyName . ' ' . strtoupper($orderByOption->direction);
        }

        return implode(', ', $orderParts);
    }

    protected function buildConversation(int $conversationId): AIConversation
    {
        $aiConversation = new AIConversation();
        $aiConversation->id = $conversationId;
        $aiConversation->accountId = static::ACCOUNT_ID;

        return $aiConversation;
    }

    protected function buildCall(): AgentVoiceCall
    {
        $agentVoiceCall = new AgentVoiceCall();
        $agentVoiceCall->id = static::CALL_ID;
        $agentVoiceCall->accountId = static::ACCOUNT_ID;
        $agentVoiceCall->aiConversationId = static::CONVERSATION_ID;
        $agentVoiceCall->direction = AgentVoiceCall::DIRECTION_OUTBOUND;

        return $agentVoiceCall;
    }
}
