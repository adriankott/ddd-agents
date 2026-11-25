<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Installation;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * One finding of the installation check (`app:agent:check-installation`,
 * {@see \DDD\Domain\AI\Services\Installation\AgentInstallationCheckService}): which check found it, how bad it is,
 * what it concerns, what is wrong and how to fix it.
 *
 * - {@see SEVERITY_BLOCKING}: the agent fails at runtime as installed (a bound class that does not exist, a table the
 *   installed version reads that the schema lacks); the command exits 1.
 * - {@see SEVERITY_WARNING}: something the host should look at (a package skill nobody offers, a right held by
 *   nobody, a renamed config key still set); the command exits 0.
 */
class AgentInstallationFinding extends ValueObject
{
    public const string SEVERITY_BLOCKING = 'BLOCKING';

    public const string SEVERITY_WARNING = 'WARNING';

    /** @var string The name of the check that found it (`unboundPackageSkills`, `missingTables`, …) */
    public string $checkName = '';

    /** @var string {@see SEVERITY_BLOCKING} or {@see SEVERITY_WARNING} */
    #[Choice(choices: [self::SEVERITY_BLOCKING, self::SEVERITY_WARNING])]
    public string $severity = self::SEVERITY_WARNING;

    /** @var string What the finding concerns: a skill name, a class, a table, a config key, a command name */
    public string $subject = '';

    /** @var string What is wrong, one sentence */
    public string $message = '';

    /** @var string|null How to fix it; null = no hint */
    public ?string $hint = null;

    public static function create(string $checkName, string $severity, string $subject, string $message, ?string $hint = null): static
    {
        $agentInstallationFinding = static::newInstance();
        $agentInstallationFinding->checkName = $checkName;
        $agentInstallationFinding->severity = $severity === self::SEVERITY_BLOCKING ? self::SEVERITY_BLOCKING : self::SEVERITY_WARNING;
        $agentInstallationFinding->subject = $subject;
        $agentInstallationFinding->message = $message;
        $agentInstallationFinding->hint = $hint;
        return $agentInstallationFinding;
    }

    public function isBlocking(): bool
    {
        return $this->severity === self::SEVERITY_BLOCKING;
    }

    /** Two findings with the same content are both kept (each is its own line of the report) */
    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic((string)spl_object_id($this));
    }
}
