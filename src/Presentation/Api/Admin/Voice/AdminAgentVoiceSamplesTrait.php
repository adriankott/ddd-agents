<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Voice;

use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Services\Voice\Samples\AgentVoiceSamplesService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Api\Admin\Voice\Dtos\AdminAgentVoiceSampleGetRequestDto;
use DDD\Presentation\Base\Dtos\UntrustedContentResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Summary;
use DDD\Presentation\Base\Router\Routes\Get;

/**
 * The voice sample endpoint of an admin API: one voice speaking the sample sentence in one language, as WAV from the
 * application's object store ({@see AgentVoiceSamplesService}; `app:agent:voice-samples:generate` makes the samples).
 * The bytes go through the backend only: no client reaches the store, no presigned URL. An application mounts it with
 * one controller of its own, which sets the path prefix, the tag and the access (typically beside the voice settings of
 * its voice picker):
 *
 *     #[Route('/ai/voiceSettings')]
 *     #[Tag(group: 'AI', name: 'AgentVoiceSettings', description: 'The voice settings of the signed-in admin')]
 *     class AdminAgentVoiceSettingsController extends AdminController
 *     {
 *         use AdminAgentVoiceSamplesTrait;
 *
 *         protected function throwForbiddenUnlessVoiceSamplesAccessAllowed(Account $account): void { … }
 *     }
 *
 * The bytes were spoken by the provider, not produced by the server, so they go out as untrusted content
 * ({@see UntrustedContentResponseDto}): the server's type `audio/wav`, `nosniff`, `CSP: sandbox`, an attachment
 * disposition (a player fetches the bytes and plays them from a blob, so the disposition does not stand in its way).
 *
 * The route only reads (classify it as a read where an application tells reads from writes). The answer carries an
 * ETag, the sample's fingerprint (model, voice, language, sentence, format), and may be kept privately for a day: a new
 * model or sentence is a new fingerprint, so a browser never plays an outdated sample under the current one for longer.
 *
 * While the voice channel is switched off (`AI.agentVoice.enabled`) the route answers `403 feature_not_available`.
 * Override points: {@see self::throwForbiddenUnlessVoiceSamplesAccessAllowed()} (who may listen; default: every signed-in
 * account the application's path access lets through), {@see self::getVoiceSamplesServiceOfAdminEndpoints()}.
 */
trait AdminAgentVoiceSamplesTrait
{
    /**
     * The sample of a voice in a language: `audio/wav`, a few seconds. 404 `sample_not_found` when no sample exists for
     * the voice and language (not offered, or not generated yet). `voiceId` carries its own requirement: it is a voice
     * name, and the router's default for `…Id` parameters (`\d*`, ddd's Route::addRouteParamRequirements()) would match
     * no voice, so every sample would answer 404.
     *
     * @throws ForbiddenException `feature_not_available` while the voice channel is off; without a signed-in account
     * @throws NotFoundException `sample_not_found`
     * @throws InternalErrorException the object store of the samples is not configured
     */
    #[Get('/samples/{voiceId}/{languageCode}', requirements: ['voiceId' => '[a-z][a-z0-9_-]*', 'languageCode' => '[a-z]{2}'])]
    #[Summary('AgentVoiceSample Get')]
    public function getAgentVoiceSample(AdminAgentVoiceSampleGetRequestDto $requestDto): UntrustedContentResponseDto
    {
        if (!AgentVoiceConfiguration::current()->enabled) {
            throw new ForbiddenException('feature_not_available');
        }
        $signedInAccount = AuthService::instance()->getAccount();
        if (!$signedInAccount instanceof Account || !isset($signedInAccount->id)) {
            throw new ForbiddenException('No authenticated account');
        }
        $this->throwForbiddenUnlessVoiceSamplesAccessAllowed($signedInAccount);
        $agentVoiceSamplesService = $this->getVoiceSamplesServiceOfAdminEndpoints();
        $sampleAudio = $agentVoiceSamplesService->findSampleAudio($requestDto->voiceId, $requestDto->languageCode);
        if ($sampleAudio === null) {
            throw new NotFoundException('sample_not_found');
        }

        return new UntrustedContentResponseDto(
            $sampleAudio,
            AgentVoiceSamplesService::CONTENT_TYPE,
            "{$requestDto->voiceId}-{$requestDto->languageCode}.wav",
            false,
            200,
            [
                'Cache-Control' => 'private, max-age=86400',
                'ETag' => '"' . $agentVoiceSamplesService->buildSampleFingerprint($requestDto->voiceId, $requestDto->languageCode) . '"',
            ]
        );
    }

    /**
     * The access hook of the sample route, called after the switch and the sign-in check. Empty by default: the
     * application restricts the path (`access_control`) and overrides this for an account predicate (e.g. "may use the
     * agent") by throwing a {@see ForbiddenException}.
     *
     * @throws ForbiddenException
     */
    protected function throwForbiddenUnlessVoiceSamplesAccessAllowed(Account $account): void
    {
    }

    /** The samples service; resolved from the container, so an application's subclass at the same path answers. */
    protected function getVoiceSamplesServiceOfAdminEndpoints(): AgentVoiceSamplesService
    {
        /** @var AgentVoiceSamplesService $agentVoiceSamplesService */
        $agentVoiceSamplesService = DDDService::instance()->getService(AgentVoiceSamplesService::class);

        return $agentVoiceSamplesService;
    }
}
