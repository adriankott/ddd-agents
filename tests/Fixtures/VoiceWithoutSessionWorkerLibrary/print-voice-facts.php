<?php

declare(strict_types=1);

/**
 * Prints, as one JSON document, how the voice channel reads in the kernel that
 * `kernel-without-session-worker-library.php` builds (the voice channel switched on, the WebSocket library of the
 * session worker not installed): what is a service, what the one place answers and what each of its askers answers.
 */

use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\MessageHandlers\Voice\SessionWorker\VoiceLiveSessionAttachHandler;
use DDD\Domain\AI\MessageHandlers\Voice\SessionWorker\VoiceLiveSessionAttachMessage;
use DDD\Domain\AI\Services\Conversations\Channels\AgentVoiceChannelService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Domain\AI\Services\Voice\AgentVoiceChannelProviderService;
use DDD\Domain\AI\Services\Voice\Live\LiveSessionCreationResponse;
use DDD\Domain\AI\Services\Voice\Live\OpenAILiveVoiceProviderAdapter;
use DDD\Domain\AI\Services\Voice\Providers\RealtimeVoiceProviderAdapter;
use DDD\Domain\AI\Services\Voice\SessionWorker\AgentLiveVoiceSessionsService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\DDDService;
use DDD\Modules\Agents\AgentsModule;
use DDD\Presentation\Mcp\Client\Voice\AgentVoiceCallsMCPPackage;

$kernel = require __DIR__ . '/kernel-without-session-worker-library.php';
$kernel->boot();
$container = $kernel->getContainer();

/** @var AgentVoiceCallAdmissionService $agentVoiceCallAdmissionService */
$agentVoiceCallAdmissionService = DDDService::instance()->getService(AgentVoiceCallAdmissionService::class);
/** @var AgentVoiceChannelProviderService $agentVoiceChannelProviderService */
$agentVoiceChannelProviderService = DDDService::instance()->getService(AgentVoiceChannelProviderService::class);
/** @var AgentVoiceChannelService $agentVoiceChannelService */
$agentVoiceChannelService = DDDService::instance()->getService(AgentVoiceChannelService::class);
$voiceSkillBinding = [AISkill::AGENT_VOICE => [AgentVoiceCallsMCPPackage::class]];

echo json_encode([
    'libraryClassExists' => class_exists(AgentsModule::VOICE_SESSION_WORKER_LIBRARY_CLASS),
    'sessionWorkerIsAService' => $container->has(AgentLiveVoiceSessionsService::class),
    'attachHandlerIsOfAnInactivePart' => AgentsModule::isClassOfAnInactivePart(VoiceLiveSessionAttachHandler::class),
    'attachMessageClassIsLoadable' => class_exists(VoiceLiveSessionAttachMessage::class),
    'callsServiceIsAService' => $container->has(AgentVoiceCallsService::class),
    'canVoiceRunHere' => $agentVoiceCallAdmissionService->canVoiceRunHere(),
    'isVoiceSwitchedOnWithoutSessionWorkerLibrary' => $agentVoiceCallAdmissionService->isVoiceSwitchedOnWithoutSessionWorkerLibrary(),
    'refusalCodeOfACallStart' => $agentVoiceCallAdmissionService->findRefusalOfCallStart(Account::newInstance(), null, null)?->code,
    'voiceChannelProviderIsAvailable' => $agentVoiceChannelProviderService->isVoiceChannelAvailable(),
    'activeVoiceChannelProviderClassName' => ($activeVoiceChannelProvider = $agentVoiceChannelService->findActiveVoiceChannelProvider()) !== null ? $activeVoiceChannelProvider::class : null,
    'toolPackageIsAvailable' => AgentVoiceCallsMCPPackage::isAvailable(),
    'voiceSkillBindingWithoutUnavailablePackages' => AISkill::withoutUnavailablePackages($voiceSkillBinding),
    'providerAdapterClassNameOfTheDefaultProvider' => RealtimeVoiceProviderAdapter::forProviderName(null)::class,
    'providerAdapterIsTheShippedOne' => RealtimeVoiceProviderAdapter::forProviderName(null) instanceof OpenAILiveVoiceProviderAdapter,
    'sessionCreationResponseClassIsLoadable' => class_exists(LiveSessionCreationResponse::class),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
