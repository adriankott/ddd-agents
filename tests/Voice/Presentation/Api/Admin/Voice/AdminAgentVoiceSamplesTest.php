<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Admin\Voice;

use DDD\Domain\AI\Services\Voice\Samples\AgentVoiceSamplesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Api\Admin\Voice\AdminAgentVoiceSamplesTrait;
use DDD\Presentation\Api\Admin\Voice\Dtos\AdminAgentVoiceSampleGetRequestDto;
use DDD\Presentation\Base\Dtos\UntrustedContentResponseDto;
use DDD\Presentation\Base\Router\Routes\Route;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionAttribute;
use ReflectionMethod;

/**
 * The admin sample endpoint: one GET route below the application's prefix; switched off it answers 403
 * `feature_not_available` and without a signed-in account it refuses, both before the store is read; the access hook
 * runs next and its refusal stops the route; a sample answers as `audio/wav` with the fingerprint as ETag and a private
 * day of caching; a missing sample answers 404 `sample_not_found`.
 */
class AdminAgentVoiceSamplesTest extends AgentVoiceIntegrationTestCase
{
    protected AdminAgentVoiceSamplesFixtureController $fixtureController;

    protected AgentVoiceSamplesService&MockObject $agentVoiceSamplesService;

    protected Account $signedInAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true]));
        $this->signedInAccount = new Account();
        $this->signedInAccount->id = 12;
        AuthService::instance()->setAccount($this->signedInAccount);
        $this->agentVoiceSamplesService = $this->createMock(AgentVoiceSamplesService::class);
        $this->fixtureController = new AdminAgentVoiceSamplesFixtureController();
        $this->fixtureController->agentVoiceSamplesService = $this->agentVoiceSamplesService;
    }

    protected function buildRequest(string $voiceId = 'flitz', string $languageCode = 'de'): AdminAgentVoiceSampleGetRequestDto
    {
        $requestDto = new AdminAgentVoiceSampleGetRequestDto();
        $requestDto->voiceId = $voiceId;
        $requestDto->languageCode = $languageCode;

        return $requestDto;
    }

    public function testASampleAnswersAsUntrustedWaveWithItsFingerprintAndAPrivateDayOfCaching(): void
    {
        $this->agentVoiceSamplesService->expects(static::once())->method('findSampleAudio')->with('flitz', 'de')->willReturn('RIFF-wave-bytes');
        $this->agentVoiceSamplesService->method('buildSampleFingerprint')->with('flitz', 'de')->willReturn('0123456789abcdef');

        $fileResponseDto = $this->fixtureController->getAgentVoiceSample($this->buildRequest());

        static::assertSame(200, $fileResponseDto->getStatusCode());
        static::assertSame('RIFF-wave-bytes', $fileResponseDto->getContent());
        static::assertSame('audio/wav', $fileResponseDto->headers->get('Content-Type'));
        static::assertSame('"0123456789abcdef"', $fileResponseDto->headers->get('ETag'));
        static::assertStringContainsString('private', (string)$fileResponseDto->headers->get('Cache-Control'));
        static::assertStringContainsString('max-age=86400', (string)$fileResponseDto->headers->get('Cache-Control'));
        static::assertSame([$this->signedInAccount], $this->fixtureController->accountsAskedForAccess);
        static::assertInstanceOf(UntrustedContentResponseDto::class, $fileResponseDto, 'the provider spoke the bytes');
        static::assertSame('nosniff', $fileResponseDto->headers->get('X-Content-Type-Options'));
        static::assertStringContainsString('sandbox', (string)$fileResponseDto->headers->get('Content-Security-Policy'));
        static::assertStringStartsWith('attachment', (string)$fileResponseDto->headers->get('Content-Disposition'));
        static::assertStringContainsString('flitz-de.wav', (string)$fileResponseDto->headers->get('Content-Disposition'));
    }

    public function testAMissingSampleAnswersSampleNotFound(): void
    {
        $this->agentVoiceSamplesService->method('findSampleAudio')->willReturn(null);

        try {
            $this->fixtureController->getAgentVoiceSample($this->buildRequest('nuri', 'en'));
            static::fail('a missing sample must answer 404');
        } catch (NotFoundException $notFound) {
            static::assertSame('sample_not_found', $notFound->getMessage());
        }
    }

    public function testSwitchedOffTheRouteAnswersFeatureNotAvailableBeforeAnyRead(): void
    {
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => false]));
        $this->agentVoiceSamplesService->expects(static::never())->method(static::anything());

        try {
            $this->fixtureController->getAgentVoiceSample($this->buildRequest());
            static::fail('switched off the route must refuse');
        } catch (ForbiddenException $refusal) {
            static::assertSame('feature_not_available', $refusal->getMessage());
        }
        static::assertSame([], $this->fixtureController->accountsAskedForAccess);
    }

    public function testWithoutASignedInAccountTheRouteRefusesBeforeAnyRead(): void
    {
        $noAccount = null;
        AuthService::instance()->setAccount($noAccount);
        $this->agentVoiceSamplesService->expects(static::never())->method(static::anything());

        $this->expectException(ForbiddenException::class);
        $this->fixtureController->getAgentVoiceSample($this->buildRequest());
    }

    public function testTheAccessHookRefusalStopsTheRouteBeforeAnyRead(): void
    {
        $this->fixtureController->accessRefused = true;
        $this->agentVoiceSamplesService->expects(static::never())->method(static::anything());

        try {
            $this->fixtureController->getAgentVoiceSample($this->buildRequest());
            static::fail('the access hook must stop the route');
        } catch (ForbiddenException $refusal) {
            static::assertSame('admin_refused', $refusal->getMessage());
        }
    }

    public function testTheTraitRoutesOneGetRoute(): void
    {
        $routeAttributes = (new ReflectionMethod(AdminAgentVoiceSamplesTrait::class, 'getAgentVoiceSample'))->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF);

        static::assertCount(1, $routeAttributes);
        $route = $routeAttributes[0]->newInstance();
        static::assertSame(['GET'], $route->methods);
        static::assertSame('/samples/{voiceId}/{languageCode}', $route->path);
        // a voice is a name; the router's default for `…Id` parameters is digits
        static::assertMatchesRegularExpression('#^(' . $route->requirements['voiceId'] . ')$#', 'flitz');
        static::assertMatchesRegularExpression('#^(' . $route->requirements['languageCode'] . ')$#', 'de');
    }
}
