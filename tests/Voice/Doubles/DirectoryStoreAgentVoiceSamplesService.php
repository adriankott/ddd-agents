<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Doubles;

use DDD\Domain\AI\Services\Voice\Samples\AgentVoiceSamplesService;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\ObjectStorage\BasicObjectStorage;
use DDD\Infrastructure\Services\Service;

/** The samples service on a store the test hands in (a directory store) and the recording synthesizer. */
class DirectoryStoreAgentVoiceSamplesService extends AgentVoiceSamplesService
{
    public ?BasicObjectStorage $stubbedObjectStorage = null;

    public RecordingLiveVoiceSampleSynthesizer $recordingSynthesizer;

    /** @var string The live model the samples are made with (the default provider's in production) */
    public string $liveModel = 'gpt-live-1.1';

    public function __construct()
    {
        $this->recordingSynthesizer = new RecordingLiveVoiceSampleSynthesizer();
    }

    public function getBasicObjectStorage(): BasicObjectStorage
    {
        return $this->stubbedObjectStorage ?? throw new InternalErrorException('The object store "agentVoiceSamples" of the voice samples is not configured');
    }

    protected function getLiveVoiceSampleSynthesizer(): Service
    {
        return $this->recordingSynthesizer;
    }

    protected function getLiveModel(): string
    {
        return $this->liveModel;
    }

    public static function forgetSamplesReadInThisProcess(): void
    {
        static::$samplesReadInThisProcess = [];
    }
}
