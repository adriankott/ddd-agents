<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Lines;

use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmSecretRedaction;
use DateTimeInterface;

/**
 * The formats the lines of the System tools share: a time, a duration, the short name of a class, a logged text.
 * Pure functions.
 */
class SystemLineFormats
{
    /** @var string What a line shows for a time that was never set */
    public const string NEVER = 'never';

    /**
     * @param DateTimeInterface|null $dateTime
     * @param bool $withSeconds
     * @return string `2026-11-20 08:12 UTC`; NEVER for none
     */
    public static function formatTime(?DateTimeInterface $dateTime, bool $withSeconds = false): string
    {
        if ($dateTime === null) {
            return self::NEVER;
        }
        return gmdate($withSeconds ? 'Y-m-d H:i:s' : 'Y-m-d H:i', $dateTime->getTimestamp()) . ' UTC';
    }

    /**
     * @param int|float|null $milliseconds
     * @return string `850 ms` · `42 s` · `2 min 5 s` · `1 h 12 min`; `?` for none
     */
    public static function formatDuration(int|float|null $milliseconds): string
    {
        if ($milliseconds === null) {
            return '?';
        }
        $milliseconds = (int)round($milliseconds);
        if ($milliseconds < 1000) {
            return $milliseconds . ' ms';
        }
        $seconds = intdiv($milliseconds, 1000);
        if ($seconds < 60) {
            return $seconds < 10 ? number_format($milliseconds / 1000, 1, '.', '') . ' s' : $seconds . ' s';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' min' . ($seconds % 60 > 0 ? ' ' . ($seconds % 60) . ' s' : '');
        }
        return intdiv($seconds, 3600) . ' h' . (intdiv($seconds % 3600, 60) > 0 ? ' ' . intdiv($seconds % 3600, 60) . ' min' : '');
    }

    /**
     * @param string|null $className A class name with its namespace
     * @return string The name without the namespace; '' for none
     */
    public static function shortClassName(?string $className): string
    {
        $className = trim((string)$className);
        if ($className === '') {
            return '';
        }
        $namespaceEnd = strrpos($className, '\\');
        return $namespaceEnd === false ? $className : substr($className, $namespaceEnd + 1);
    }

    /**
     * A text the system logged (an error message, a line of output), ready for a line: its first line, with
     * credentials and e-mail addresses masked, cut, in double quotes.
     *
     * @param string|null $text
     * @param int $maxLength
     * @return string '' for no text
     */
    public static function quoteLoggedText(?string $text, int $maxLength): string
    {
        $firstLine = LlmSecretRedaction::redactFirstLine($text, $maxLength);
        return $firstLine === '' ? '' : '"' . str_replace('"', "'", $firstLine) . '"';
    }

    /**
     * The name of a console command as a line shows it. A stored command line whose first word is no command name
     * (an environment assignment, a path, a quoted text) is not shown: that word can be a secret.
     *
     * @param string|null $commandName The first word of a stored command line
     * @return string `app:feeds:import-items`; `no command` for none; LlmSecretRedaction::MASK otherwise
     */
    public static function formatCommandName(?string $commandName): string
    {
        $commandName = trim((string)$commandName);
        if ($commandName === '') {
            return 'no command';
        }
        return preg_match('/^[a-z][a-z0-9]*(?:[:_\-][a-z0-9]+)*$/i', $commandName) === 1 ? $commandName : LlmSecretRedaction::MASK;
    }

    /**
     * The console command of a logged command line, without its arguments, options and environment: a command line is
     * never shown.
     *
     * @param string|null $commandLine e.g. `php bin/console app:issues:show --environment=prod`
     * @return string `app:issues:show`; the first word through formatCommandName() when no word is a namespaced command
     */
    public static function commandNameOfCommandLine(?string $commandLine): string
    {
        $words = preg_split('/\s+/u', trim((string)$commandLine)) ?: [];
        foreach ($words as $word) {
            if (preg_match('/^[a-z][a-z0-9_\-]*(?::[a-z0-9_\-]+)+$/i', $word) === 1) {
                return $word;
            }
        }
        return static::formatCommandName($words[0] ?? null);
    }

    /**
     * @param int $count
     * @param string $singular e.g. "run"
     * @return string `1 run` · `3 runs`
     */
    public static function countOf(int $count, string $singular): string
    {
        return number_format($count) . ' ' . $singular . ($count === 1 ? '' : 's');
    }
}
