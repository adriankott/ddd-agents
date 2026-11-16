<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\RecordedRuns;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunFields;
use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSessionInterface;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The recording session interface carries exactly the calls a harness makes on a session, with their types, and a
 * session typed against it can be driven through one run.
 */
class AgentRecordedRunSessionInterfaceTest extends UnitTestCase
{
    /** @var array<string, string> The signature of every call, by method name: parameter types, then the return type */
    public const array SIGNATURES_BY_METHOD_NAME = [
        'beginTurn' => 'int: void',
        'buildRecording' => 'DDD\Domain\AI\Entities\Conversations\AIConversation, array, DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunFields: DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunRecording',
        'compareWithRecording' => 'DDD\Domain\AI\Entities\Conversations\AIConversation, array: DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunComparison',
        'concludeRun' => 'DDD\Domain\AI\Entities\Conversations\AIConversation, array: void',
        'describeRun' => 'DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSnapshot: string',
        'describeSharedScopeTables' => ': ?string',
        'isReplay' => ': bool',
        'readHighWaterMarks' => ': void',
        'runQueuedMessages' => ': int',
        'setRootConversation' => 'DDD\Domain\AI\Entities\Conversations\AIConversation: void',
        'setScopeIds' => 'array: void',
        'start' => ': void',
        'stop' => 'bool: void',
    ];

    public function testTheInterfaceCarriesExactlyTheCallsOfAHarness(): void
    {
        $signaturesByMethodName = [];
        foreach ((new ReflectionClass(AgentRecordedRunSessionInterface::class))->getMethods() as $reflectionMethod) {
            $signaturesByMethodName[$reflectionMethod->getName()] = $this->describeSignature($reflectionMethod);
        }
        ksort($signaturesByMethodName);

        self::assertSame(self::SIGNATURES_BY_METHOD_NAME, $signaturesByMethodName);
        self::assertTrue((new ReflectionMethod(AgentRecordedRunSessionInterface::class, 'stop'))->getParameters()[0]->getDefaultValue());
    }

    public function testASessionIsDrivenThroughOneRunByTheInterface(): void
    {
        $recordedRunSessionDouble = new RecordedRunSessionDouble();
        $rootConversation = AIConversation::newInstance();

        $this->driveOneRecordedRun($recordedRunSessionDouble, $rootConversation);

        self::assertSame(
            ['setScopeIds', 'setRootConversation', 'start', 'beginTurn', 'runQueuedMessages', 'stop', 'isReplay', 'buildRecording', 'describeSharedScopeTables', 'describeRun'],
            $recordedRunSessionDouble->calledMethodNames
        );
        self::assertSame(['account' => 1], $recordedRunSessionDouble->scopeIdsByName);
    }

    /** The calls a console harness makes on a session over one owner turn, typed against the interface only. */
    protected function driveOneRecordedRun(AgentRecordedRunSessionInterface $agentRecordedRunSession, AIConversation $rootConversation): void
    {
        $agentRecordedRunSession->setScopeIds(['account' => 1]);
        $agentRecordedRunSession->setRootConversation($rootConversation);
        $agentRecordedRunSession->start();
        try {
            $agentRecordedRunSession->beginTurn(1);
            $agentRecordedRunSession->runQueuedMessages();
        } finally {
            $agentRecordedRunSession->stop(runRemainingMessages: false);
        }
        if (!$agentRecordedRunSession->isReplay()) {
            $agentRecordedRunRecording = $agentRecordedRunSession->buildRecording($rootConversation, ['account' => 1], AgentRecordedRunFields::fromValuesByField(['turns' => ['hello']]));
            $agentRecordedRunSession->describeSharedScopeTables();
            self::assertSame(['hello'], $agentRecordedRunRecording->getTurns());
            self::assertSame('2 sections', $agentRecordedRunSession->describeRun($agentRecordedRunRecording->getSnapshot()));
        }
    }

    protected function describeSignature(ReflectionMethod $reflectionMethod): string
    {
        $parameterTypes = [];
        foreach ($reflectionMethod->getParameters() as $reflectionParameter) {
            $parameterTypes[] = $this->describeType($reflectionParameter->getType());
        }
        return implode(', ', $parameterTypes) . ': ' . $this->describeType($reflectionMethod->getReturnType());
    }

    protected function describeType(?\ReflectionType $reflectionType): string
    {
        if (!$reflectionType instanceof ReflectionNamedType) {
            return (string)$reflectionType;
        }
        return ($reflectionType->allowsNull() && $reflectionType->getName() !== 'mixed' ? '?' : '') . $reflectionType->getName();
    }
}
