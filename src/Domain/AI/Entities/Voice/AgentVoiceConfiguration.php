<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Libs\Config;

/**
 * The configuration of the voice channel as one typed value: `AI.agentVoice` (`config/app/AI/agentVoice.php`, replaced
 * as a whole by the application) and the agent name of the agent's persona (`AI.agentPersona`). Every reader of the
 * voice channel asks this value, never the config array.
 *
 * {@see $enabled} is the plain switch. Whether the voice channel can RUN in the installation (the switch, and the
 * session worker's WebSocket library) is answered in one place,
 * {@see \DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService::canVoiceRunHere()}; only what reads or tidies
 * the call table asks the switch alone.
 *
 * Built on each call of {@see current()}: reading the config is an array access, and a value kept per process would
 * outlive a changed config in a long-running worker and in tests.
 */
class AgentVoiceConfiguration extends ValueObject
{
    /** @var string The config path of the voice channel */
    public const string CONFIG_KEY = 'AI.agentVoice';

    /** @var string The config path of the agent's persona */
    public const string CONFIG_KEY_AGENT_PERSONA = 'AI.agentPersona';

    /** @var string A call is kept as a row of the package's call table */
    public const string CALL_STORE_ENTITY = 'entity';

    /** @var bool Whether the application switched the voice channel on (`AI.agentVoice.enabled`) */
    public bool $enabled = false;

    /**
     * @var string The name of the store that keeps calls: {@see self::CALL_STORE_ENTITY}, or a name the application
     *             maps to a store of its own. Kept as configured, so a name no store answers to is refused where a
     *             call is read or written and never replaced by another store.
     */
    public string $callStore = self::CALL_STORE_ENTITY;

    /** @var string The name of the provider adapter a new call is created on */
    public string $defaultProviderName = '';

    /** @var array<string, string> Provider adapter name => adapter class */
    public array $providerAdapterClassNames = [];

    /** @var array<string, array<string, mixed>> Provider adapter name => its settings */
    public array $providerSettings = [];

    /** @var string|null The language of a call; null = the language of its conversation */
    public ?string $language = null;

    /** @var int The longest a call stays live, in seconds */
    public int $maximumCallSeconds = 3600;

    /** @var int|null The seconds of calls an account may book per day; null = no daily cap */
    public ?int $dailySecondsPerAccount = null;

    /** @var int How long a call the agent started rings before it expires */
    public int $ringSeconds = 90;

    /** @var int Nobody spoke for this long: the model asks once */
    public int $speechSilentNudgeSeconds = 45;

    /** @var int Still nobody spoke for this long: a short goodbye, then the call is hung up */
    public int $speechSilentHangUpSeconds = 90;

    /** @var int No event from the provider for this long: the call is gone */
    public int $sessionSilentSeconds = 300;

    /** @var int How often the session worker asks whether the running call may go on */
    public int $admissionRecheckSeconds = 30;

    /** @var int The smallest slice of session seconds the worker books */
    public int $usageBookingMinimumSeconds = 30;

    /** @var string The agent's name of the application's persona; empty when the persona names nobody */
    public string $agentName = '';

    /** The configuration as the application has it now. */
    public static function current(): static
    {
        return static::fromConfigEntries(Config::get(static::CONFIG_KEY), Config::get(static::CONFIG_KEY_AGENT_PERSONA));
    }

    /**
     * Builds the value from the two config entries as they are read; anything missing or of the wrong type keeps its
     * default.
     */
    public static function fromConfigEntries(mixed $voiceConfigEntries, mixed $agentPersonaConfigEntries): static
    {
        $agentVoiceConfiguration = new static();
        if (is_array($agentPersonaConfigEntries) && is_string($agentPersonaConfigEntries['agentName'] ?? null)) {
            $agentVoiceConfiguration->agentName = trim($agentPersonaConfigEntries['agentName']);
        }
        if (!is_array($voiceConfigEntries)) {
            return $agentVoiceConfiguration;
        }
        $agentVoiceConfiguration->enabled = ($voiceConfigEntries['enabled'] ?? false) === true;
        if (is_string($voiceConfigEntries['callStore'] ?? null) && trim($voiceConfigEntries['callStore']) !== '') {
            $agentVoiceConfiguration->callStore = trim($voiceConfigEntries['callStore']);
        }
        if (array_key_exists('language', $voiceConfigEntries) && is_string($voiceConfigEntries['language']) && trim($voiceConfigEntries['language']) !== '') {
            $agentVoiceConfiguration->language = trim($voiceConfigEntries['language']);
        }
        $agentVoiceConfiguration->applyProviderEntries($voiceConfigEntries['provider'] ?? null);
        $agentVoiceConfiguration->applyLimitEntries($voiceConfigEntries['limits'] ?? null);

        return $agentVoiceConfiguration;
    }

    /** Whether calls are kept as rows of the call table. */
    public function keepsCallsInTheCallTable(): bool
    {
        return $this->callStore === static::CALL_STORE_ENTITY;
    }

    /**
     * Whether the call table is in use: voice is switched on and calls are kept in it. Only then the table is
     * required, and only then anything of the package may read or write it. The switch alone is asked, not whether
     * voice can run in the installation: what reads and tidies the table works without the session worker's library.
     */
    public function isCallTableInUse(): bool
    {
        return $this->enabled && $this->keepsCallsInTheCallTable();
    }

    /** The adapter class registered under `$providerName`; null when no adapter has that name. */
    public function findProviderAdapterClassName(string $providerName): ?string
    {
        return $this->providerAdapterClassNames[$providerName] ?? null;
    }

    /** One setting of a provider (`AI.agentVoice.provider.<providerName>.<settingName>`); null when it is not set. */
    public function findProviderSetting(string $providerName, string $settingName): mixed
    {
        return $this->providerSettings[$providerName][$settingName] ?? null;
    }

    /** Reads `provider`: the default adapter name, the adapter classes and each adapter's settings. */
    protected function applyProviderEntries(mixed $providerEntries): void
    {
        if (!is_array($providerEntries)) {
            return;
        }
        if (is_string($providerEntries['default'] ?? null)) {
            $this->defaultProviderName = $providerEntries['default'];
        }
        if (!is_array($providerEntries['adapters'] ?? null)) {
            return;
        }
        foreach ($providerEntries['adapters'] as $providerName => $adapterClassName) {
            if (!is_string($providerName) || !is_string($adapterClassName)) {
                continue;
            }
            // without a leading backslash: the name the container and `static::class` know the class by
            $this->providerAdapterClassNames[$providerName] = ltrim($adapterClassName, '\\');
            $this->providerSettings[$providerName] = is_array($providerEntries[$providerName] ?? null) ? $providerEntries[$providerName] : [];
        }
    }

    /** Reads `limits`: every positive whole number replaces its default; the daily cap may be null. */
    protected function applyLimitEntries(mixed $limitEntries): void
    {
        if (!is_array($limitEntries)) {
            return;
        }
        foreach (['maximumCallSeconds', 'ringSeconds', 'speechSilentNudgeSeconds', 'speechSilentHangUpSeconds', 'sessionSilentSeconds', 'admissionRecheckSeconds', 'usageBookingMinimumSeconds'] as $limitName) {
            if (is_int($limitEntries[$limitName] ?? null) && $limitEntries[$limitName] > 0) {
                $this->$limitName = $limitEntries[$limitName];
            }
        }
        if (is_int($limitEntries['dailySecondsPerAccount'] ?? null) && $limitEntries['dailySecondsPerAccount'] > 0) {
            $this->dailySecondsPerAccount = $limitEntries['dailySecondsPerAccount'];
        }
    }
}
