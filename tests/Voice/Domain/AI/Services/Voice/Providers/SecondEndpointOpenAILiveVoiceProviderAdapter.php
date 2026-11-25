<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Providers;

use DDD\Domain\AI\Services\Voice\Live\OpenAILiveVoiceProviderAdapter;

/**
 * A second endpoint of the same provider, as an application writes it: a subclass of the shipped adapter, registered
 * under a name of its own, reading that name's settings.
 */
class SecondEndpointOpenAILiveVoiceProviderAdapter extends OpenAILiveVoiceProviderAdapter
{
}
