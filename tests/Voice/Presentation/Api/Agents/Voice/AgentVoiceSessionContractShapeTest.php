<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Agents\Voice;

use DDD\Presentation\Api\Agents\Voice\Controller\AgentVoiceSessionsTrait;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionAcknowledgeResponseDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionAnswerRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionAnswerResponseDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionCallStateGetRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionCallStateGetResponseDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionDeclineRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionDeviceStatusRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionHangUpRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionOpenRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionOpenResponseDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionRingRequestDto;
use DDD\Presentation\Api\Agents\Voice\Dtos\AgentVoiceSessionRingResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\Router\Routes\Route;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The shape the clients call (interfaces, section 5): the five routes of the trait with their verb and path, and the
 * field names of every request and answer, which keep the names the clients send and read today.
 */
class AgentVoiceSessionContractShapeTest extends AgentVoiceUnitTestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function provideRoutedMethods(): array
    {
        return [
            'answer' => ['answerAgentVoiceSession', 'POST', '/{callId}/answer'],
            'decline' => ['declineAgentVoiceSession', 'POST', '/{callId}/decline'],
            'hangUp' => ['hangUpAgentVoiceSession', 'POST', '/{callId}/hangUp'],
            'deviceStatus' => ['reportAgentVoiceSessionDeviceStatus', 'POST', '/{callId}/deviceStatus'],
            'call state' => ['getAgentVoiceSessionCallState', 'GET', '/{callId}'],
        ];
    }

    #[DataProvider('provideRoutedMethods')]
    public function testTheTraitRoutesItsFiveCallRoutes(string $methodName, string $expectedVerb, string $expectedPath): void
    {
        $routeAttributes = (new ReflectionMethod(AgentVoiceSessionsTrait::class, $methodName))->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF);

        static::assertCount(1, $routeAttributes);
        $route = $routeAttributes[0]->newInstance();
        static::assertSame([$expectedVerb], $route->methods);
        static::assertSame($expectedPath, $route->path);
        static::assertSame(['callId' => '\d+'], $route->requirements);
    }

    public function testTheTwoRoutesThatNameASpaceAreNotRoutedByTheTrait(): void
    {
        $routedMethodNames = [];
        foreach ((new ReflectionClass(AgentVoiceSessionsTrait::class))->getMethods() as $traitMethod) {
            if ($traitMethod->getAttributes(Route::class, \ReflectionAttribute::IS_INSTANCEOF) !== []) {
                $routedMethodNames[] = $traitMethod->getName();
            }
        }
        sort($routedMethodNames);
        $expectedMethodNames = array_column(static::provideRoutedMethods(), 0);
        sort($expectedMethodNames);

        static::assertSame($expectedMethodNames, $routedMethodNames);
        static::assertTrue((new ReflectionMethod(AgentVoiceSessionsTrait::class, 'buildVoiceSessionOpenedResponse'))->isProtected());
        static::assertTrue((new ReflectionMethod(AgentVoiceSessionsTrait::class, 'buildVoiceCallRingingResponse'))->isProtected());
    }

    /** @return array<string, array{class-string, array<string, string>}> */
    public static function provideDtoFields(): array
    {
        return [
            'open request' => [AgentVoiceSessionOpenRequestDto::class, ['sdpOffer' => Parameter::BODY, 'conversationId' => Parameter::BODY, 'callId' => Parameter::BODY]],
            'open answer' => [AgentVoiceSessionOpenResponseDto::class, ['agentVoiceCall' => Parameter::RESPONSE, 'sdpAnswer' => Parameter::RESPONSE, 'conversationId' => Parameter::RESPONSE]],
            'ring request' => [AgentVoiceSessionRingRequestDto::class, ['task' => Parameter::BODY, 'conversationId' => Parameter::BODY]],
            'ring answer' => [AgentVoiceSessionRingResponseDto::class, ['callId' => Parameter::RESPONSE, 'conversationId' => Parameter::RESPONSE, 'ringExpiresAt' => Parameter::RESPONSE, 'anyDeviceReached' => Parameter::RESPONSE]],
            'answer request' => [AgentVoiceSessionAnswerRequestDto::class, ['callId' => Parameter::PATH, 'answerToken' => Parameter::BODY]],
            'answer answer' => [AgentVoiceSessionAnswerResponseDto::class, ['won' => Parameter::RESPONSE, 'conversationId' => Parameter::RESPONSE]],
            'decline request' => [AgentVoiceSessionDeclineRequestDto::class, ['callId' => Parameter::PATH, 'answerToken' => Parameter::BODY]],
            'hangUp request' => [AgentVoiceSessionHangUpRequestDto::class, ['callId' => Parameter::PATH, 'reason' => Parameter::BODY]],
            'deviceStatus request' => [AgentVoiceSessionDeviceStatusRequestDto::class, [
                'callId' => Parameter::PATH,
                'status' => Parameter::BODY,
                'iceCandidateType' => Parameter::BODY,
                'roundTripTimeMs' => Parameter::BODY,
                'detail' => Parameter::BODY,
                'occurredAtUnixMs' => Parameter::BODY,
            ]],
            'call state request' => [AgentVoiceSessionCallStateGetRequestDto::class, ['callId' => Parameter::PATH]],
            'call state answer' => [AgentVoiceSessionCallStateGetResponseDto::class, ['callId' => Parameter::RESPONSE, 'endedAt' => Parameter::RESPONSE, 'endReason' => Parameter::RESPONSE]],
            'acknowledgement' => [AgentVoiceSessionAcknowledgeResponseDto::class, ['acknowledged' => Parameter::RESPONSE]],
        ];
    }

    /**
     * @param class-string $dtoClassName
     * @param array<string, string> $expectedFieldLocations field name => where it travels
     */
    #[DataProvider('provideDtoFields')]
    public function testEveryDtoKeepsTheFieldNamesOfTheContract(string $dtoClassName, array $expectedFieldLocations): void
    {
        $declaredFieldLocations = [];
        foreach ((new ReflectionClass($dtoClassName))->getProperties(ReflectionProperty::IS_PUBLIC) as $dtoProperty) {
            if ($dtoProperty->getDeclaringClass()->getName() !== $dtoClassName) {
                continue;
            }
            $parameterAttributes = $dtoProperty->getAttributes(Parameter::class);
            static::assertCount(1, $parameterAttributes, $dtoClassName . '::$' . $dtoProperty->getName());
            $declaredFieldLocations[$dtoProperty->getName()] = $parameterAttributes[0]->newInstance()->in;
        }

        static::assertSame($expectedFieldLocations, $declaredFieldLocations);
    }

    public function testTheOnlyRequiredBodyFieldOfOpenIsTheOffer(): void
    {
        $requiredFieldNames = [];
        foreach ((new ReflectionClass(AgentVoiceSessionOpenRequestDto::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $dtoProperty) {
            foreach ($dtoProperty->getAttributes(Parameter::class) as $parameterAttribute) {
                if (($parameterAttribute->newInstance()->required ?? false) === true) {
                    $requiredFieldNames[] = $dtoProperty->getName();
                }
            }
        }

        static::assertSame(['sdpOffer'], $requiredFieldNames);
    }
}
