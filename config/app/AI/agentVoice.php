<?php

declare(strict_types=1);

use DDD\Domain\AI\Services\Voice\Live\OpenAILiveVoiceProviderAdapter;

/**
 * The voice channel of the agent (read as `AI.agentVoice`). An application replaces this file as a whole. No secret is
 * written here: a provider names the environment variable that holds its key.
 *
 * A switched-on voice channel also needs the WebSocket library of the session worker, which the package only suggests
 * (`composer require ratchet/pawl react/event-loop`): without it the channel reads as switched off, and
 * `app:agent:check-installation` says so (check `voiceChannel`).
 */
return [
    // false: no tool, no skill, no required table; the routes answer 403 feature_not_available
    'enabled' => false,
    // the store that keeps calls: 'entity' (the package's call table), or a name the application maps to a
    // store of its own (AgentVoiceCallStoreService::getCallStoreClassNames())
    'callStore' => 'entity',
    // a SIGTERM (a deploy, a node drain) ends a running call after one short goodbye, so the worker stops within
    // seconds and the owner can call again at once; false = the call runs to its end before the worker stops (then
    // the stop grace must exceed limits.maximumCallSeconds)
    'endCallOnTerminationSignal' => true,
    'provider' => [
        'default' => 'openAiLive',
        // adapter name => class; an application registers further adapters here. One class, one name: a second
        // endpoint of the same provider is a subclass registered under its own name
        'adapters' => ['openAiLive' => OpenAILiveVoiceProviderAdapter::class],
        'openAiLive' => [
            // a provider project with a region of its own has its own host
            'apiHost' => 'api.openai.com',
            'apiKeyEnvironmentVariable' => 'API_OPENAI_REALTIME_API_KEY',
            // also the catalog model the seconds of a session are priced by (found by its catalog name or by the
            // provider's id, the catalog's `externalId`)
            'model' => 'gpt-live-1.1',
            'defaultVoice' => 'marin',
            // the provider's storage and retention of a session
            'storeSessionAtProvider' => false,
        ],
    ],
    // null: the language of the conversation
    'language' => null,
    'limits' => [
        'maximumCallSeconds' => 3600,
        // null: no daily cap
        'dailySecondsPerAccount' => null,
        'ringSeconds' => 90,
        // nobody spoke: the model asks once
        'speechSilentNudgeSeconds' => 45,
        // still nobody: a short goodbye, then the call is hung up
        'speechSilentHangUpSeconds' => 90,
        // no event from the provider: the call is gone
        'sessionSilentSeconds' => 300,
        'admissionRecheckSeconds' => 30,
        'usageBookingMinimumSeconds' => 30,
    ],
    // the voice samples of a voice picker (DDD\Domain\AI\Services\Voice\Samples\AgentVoiceSamplesService): one
    // sentence per voice and language, spoken by the live model of the calls, stored as WAV below `keyPrefix` in the
    // object store `objectStorage` (Common.ObjectStorage, OBJECT_STORAGE_<NAME>_*) and served only through the backend
    // (DDD\Presentation\Api\Admin\Voice\AdminAgentVoiceSamplesTrait). `app:agent:voice-samples:generate` makes the
    // missing ones; a new model or sentence changes a sample's fingerprint, so the command makes it again
    'samples' => [
        'objectStorage' => 'agentVoiceSamples',
        'keyPrefix' => 'agent-voice-samples/',
        // null: the provider's selectable live voices (AgentVoiceSettings::SELECTABLE_LIVE_VOICE_IDS)
        'voiceIds' => null,
        // language => sentence; a language without a sentence has no sample. %agentName% = AI.agentPersona.agentName
        'sentences' => [
            'de' => 'Hey, ich bin %agentName%. So klinge ich, wenn wir telefonieren.',
            'en' => 'Hey, I\'m %agentName%. This is how I sound when we talk on the phone.',
        ],
    ],
];
