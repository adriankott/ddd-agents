<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStoreService;
use DDD\Domain\AI\Services\Voice\Calls\AgentVoiceCallEntityStoreService;
use DDD\Domain\AI\Services\Voice\Calls\AgentVoiceCallStore;
use DDD\Domain\AI\Services\Voice\Installation\AgentVoiceFeatureTableConditionProviderService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Voice\Doubles\ConfigurableAgentVoiceCallStoreService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The port through which the voice channel reads and writes calls: the binding `AI.agentVoice.callStore` names is the
 * one asked, a name without a store and a class that is no store throw, and every method of the store interface hands
 * its arguments to the binding unchanged and answers what the binding answered.
 */
class AgentVoiceCallStoreServiceTest extends AgentVoiceIntegrationTestCase
{
    public function testThePortIsAPublicServiceOfTheContainer(): void
    {
        static::assertInstanceOf(AgentVoiceCallStoreService::class, DDDService::instance()->getService(AgentVoiceCallStoreService::class));
        static::assertInstanceOf(AgentVoiceCallEntityStoreService::class, DDDService::instance()->getService(AgentVoiceCallEntityStoreService::class));
    }

    public function testTheBindingEntityIsTheEntityStore(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['callStore' => 'entity']));
        $entityCallStore = $this->createMock(AgentVoiceCallStore::class);
        $this->replaceService(AgentVoiceCallEntityStoreService::class, $entityCallStore);

        static::assertSame($entityCallStore, $this->getCallStoreService()->getBoundCallStore());
    }

    public function testThePortMapsOnlyTheEntityStore(): void
    {
        $getCallStoreClassNames = new ReflectionMethod(AgentVoiceCallStoreService::class, 'getCallStoreClassNames');

        static::assertSame(['entity' => AgentVoiceCallEntityStoreService::class], $getCallStoreClassNames->invoke($this->getCallStoreService()));
    }

    public function testTheShippedConfigBindsTheEntityStore(): void
    {
        $entityCallStore = $this->createMock(AgentVoiceCallStore::class);
        $this->replaceService(AgentVoiceCallEntityStoreService::class, $entityCallStore);

        static::assertSame($entityCallStore, $this->getCallStoreService()->getBoundCallStore());
    }

    public function testANameTheApplicationMapsIsBound(): void
    {
        $applicationCallStore = $this->createMock(AgentVoiceCallStore::class);
        $this->replaceService(AgentVoiceFeatureTableConditionProviderService::class, $applicationCallStore);
        $agentVoiceCallStoreService = new ConfigurableAgentVoiceCallStoreService([
            'entity' => AgentVoiceCallEntityStoreService::class,
            'applicationStore' => AgentVoiceFeatureTableConditionProviderService::class,
        ]);

        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['callStore' => ' applicationStore ']));

        static::assertSame($applicationCallStore, $agentVoiceCallStoreService->getBoundCallStore());
    }

    public function testTheBindingFollowsAChangedConfig(): void
    {
        $firstCallStore = $this->createMock(AgentVoiceCallStore::class);
        $secondCallStore = $this->createMock(AgentVoiceCallStore::class);
        $this->replaceService(AgentVoiceCallEntityStoreService::class, $firstCallStore);
        $this->replaceService(AgentVoiceFeatureTableConditionProviderService::class, $secondCallStore);
        $agentVoiceCallStoreService = new ConfigurableAgentVoiceCallStoreService([
            'entity' => AgentVoiceCallEntityStoreService::class,
            'applicationStore' => AgentVoiceFeatureTableConditionProviderService::class,
        ]);

        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['callStore' => 'entity']));
        static::assertSame($firstCallStore, $agentVoiceCallStoreService->getBoundCallStore());
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['callStore' => 'applicationStore']));
        static::assertSame($secondCallStore, $agentVoiceCallStoreService->getBoundCallStore());
    }

    #[DataProvider('provideNamesThePortDoesNotKnow')]
    public function testThePortRefusesANameNoStoreAnswersToAndNamesTheKnownOnes(string $callStoreName, string $expectedMessage): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['callStore' => $callStoreName]));

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->getCallStoreService()->getBoundCallStore();
    }

    /** @return array<string, array{string, string}> */
    public static function provideNamesThePortDoesNotKnow(): array
    {
        return [
            'conversation' => ['conversation', 'AI.agentVoice.callStore "conversation" names no call store of this installation (known: entity).'],
            'table' => ['table', 'AI.agentVoice.callStore "table" names no call store of this installation (known: entity).'],
            'Entity' => ['Entity', 'AI.agentVoice.callStore "Entity" names no call store of this installation (known: entity).'],
            'a name, trimmed in the message' => ['  table  ', 'AI.agentVoice.callStore "table" names no call store of this installation (known: entity).'],
        ];
    }

    /** @param array<string, string> $callStoreClassNames */
    #[DataProvider('provideNamesWithoutAStore')]
    public function testANameWithoutAStoreOfTheApplicationMapThrows(string $callStoreName, array $callStoreClassNames, string $expectedMessage): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['callStore' => $callStoreName]));
        $agentVoiceCallStoreService = new ConfigurableAgentVoiceCallStoreService($callStoreClassNames);

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage($expectedMessage);

        $agentVoiceCallStoreService->getBoundCallStore();
    }

    /** @return array<string, array{string, array<string, string>, string}> */
    public static function provideNamesWithoutAStore(): array
    {
        return [
            'the name is not mapped' => [
                'entity',
                ['applicationStore' => AgentVoiceCallEntityStoreService::class],
                'AI.agentVoice.callStore "entity" names no call store of this installation (known: applicationStore).',
            ],
            'the name maps to a class that does not exist' => [
                'entity',
                ['entity' => 'DDD\\Domain\\AI\\Services\\Voice\\Calls\\MissingCallStoreService'],
                'AI.agentVoice.callStore "entity" names no call store of this installation (known: entity).',
            ],
            'an unmapped name among two mapped ones' => [
                'table',
                ['entity' => AgentVoiceCallEntityStoreService::class, 'applicationStore' => AgentVoiceCallEntityStoreService::class],
                'AI.agentVoice.callStore "table" names no call store of this installation (known: entity, applicationStore).',
            ],
            'an application that maps no store' => [
                'entity',
                [],
                'AI.agentVoice.callStore "entity" names no call store of this installation (known: ).',
            ],
        ];
    }

    public function testAClassThatIsNoStoreThrows(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['callStore' => 'entity']));
        $agentVoiceCallStoreService = new ConfigurableAgentVoiceCallStoreService(['entity' => AgentVoiceFeatureTableConditionProviderService::class]);

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage(
            'The call store "entity" (DDD\\Domain\\AI\\Services\\Voice\\Installation\\AgentVoiceFeatureTableConditionProviderService) does not implement DDD\\Domain\\AI\\Services\\Voice\\Calls\\AgentVoiceCallStore.'
        );

        $agentVoiceCallStoreService->getBoundCallStore();
    }

    public function testThePortImplementsEveryMethodOfTheStore(): void
    {
        static::assertTrue((new ReflectionClass(AgentVoiceCallStoreService::class))->implementsInterface(AgentVoiceCallStore::class));
    }

    #[DataProvider('provideStoreMethodNames')]
    public function testEveryMethodHandsItsArgumentsToTheBindingAndAnswersItsAnswer(string $storeMethodName): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['callStore' => 'entity']));
        $storeMethod = new ReflectionMethod(AgentVoiceCallStore::class, $storeMethodName);
        $arguments = $this->buildArgumentsOf($storeMethod);
        $answer = $this->buildAnswerOf($storeMethod);
        $entityCallStore = $this->createMock(AgentVoiceCallStore::class);
        $expectedCall = $entityCallStore->expects(static::once())->method($storeMethodName)->with(...$arguments);
        if ($answer !== null) {
            $expectedCall->willReturn($answer);
        }
        $this->replaceService(AgentVoiceCallEntityStoreService::class, $entityCallStore);

        $actualAnswer = $this->getCallStoreService()->$storeMethodName(...$arguments);

        static::assertSame($answer, $actualAnswer);
    }

    /** @return array<string, array{string}> Every method of the store interface */
    public static function provideStoreMethodNames(): array
    {
        $storeMethodNames = [];
        foreach ((new ReflectionClass(AgentVoiceCallStore::class))->getMethods() as $storeMethod) {
            $storeMethodNames[$storeMethod->getName()] = [$storeMethod->getName()];
        }
        return $storeMethodNames;
    }

    protected function getCallStoreService(): AgentVoiceCallStoreService
    {
        /** @var AgentVoiceCallStoreService $agentVoiceCallStoreService */
        $agentVoiceCallStoreService = DDDService::instance()->getService(AgentVoiceCallStoreService::class);
        return $agentVoiceCallStoreService;
    }

    /**
     * One distinct argument per parameter of `$storeMethod`, by its type.
     *
     * @return array<int, mixed>
     */
    protected function buildArgumentsOf(ReflectionMethod $storeMethod): array
    {
        $arguments = [];
        foreach ($storeMethod->getParameters() as $parameterPosition => $parameter) {
            $parameterType = $parameter->getType();
            $typeName = $parameterType instanceof ReflectionNamedType ? $parameterType->getName() : 'mixed';
            $arguments[] = match ($typeName) {
                'int' => 7000 + $parameterPosition,
                'string' => 'argument-' . $parameterPosition,
                'bool' => true,
                'array' => [11, 12],
                AgentVoiceCall::class => $this->buildCall(70 + $parameterPosition),
                Account::class => $this->buildAccount(5),
                AIConversation::class => $this->buildConversation(30),
                default => throw new LogicException('No argument for the parameter type ' . $typeName . ' of ' . $storeMethod->getName() . '(): add one.'),
            };
        }
        return $arguments;
    }

    /** The answer the binding gives for `$storeMethod`, by its return type; null for void. */
    protected function buildAnswerOf(ReflectionMethod $storeMethod): mixed
    {
        $returnType = $storeMethod->getReturnType();
        $typeName = $returnType instanceof ReflectionNamedType ? $returnType->getName() : 'mixed';
        return match ($typeName) {
            'void' => null,
            'bool' => true,
            'int' => 4321,
            AgentVoiceCall::class => $this->buildCall(99),
            AgentVoiceCalls::class => new AgentVoiceCalls(),
            default => throw new LogicException('No answer for the return type ' . $typeName . ' of ' . $storeMethod->getName() . '(): add one.'),
        };
    }

    protected function buildCall(int $callId): AgentVoiceCall
    {
        $agentVoiceCall = new AgentVoiceCall();
        $agentVoiceCall->id = $callId;
        return $agentVoiceCall;
    }

    protected function buildAccount(int $accountId): Account
    {
        $account = Account::newInstance();
        $account->id = $accountId;
        return $account;
    }

    protected function buildConversation(int $conversationId): AIConversation
    {
        $aiConversation = AIConversation::newInstance();
        $aiConversation->id = $conversationId;
        return $aiConversation;
    }
}
