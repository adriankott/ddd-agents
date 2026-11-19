<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Services\Probes;

use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;

/** A package with a declared read, a declared write and a tool without a declared effect; each records its runs. */
class ProbeReadAndWriteMCPPackage extends MCPPackage
{
    /** The text every tool answers with */
    public const string RESULT_TEXT = 'done';

    /** @var array<int, string> the name of the tool of each run, in order */
    public static array $toolNamesOfRuns = [];

    public static function forgetRuns(): void
    {
        static::$toolNamesOfRuns = [];
    }

    /** Reads a note. */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    public function probeReadNoteUnderWriteLock(): string
    {
        static::$toolNamesOfRuns[] = __FUNCTION__;
        return static::RESULT_TEXT;
    }

    /** Writes a note. */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    public function probeWriteNoteUnderWriteLock(): string
    {
        static::$toolNamesOfRuns[] = __FUNCTION__;
        return static::RESULT_TEXT;
    }

    /** Does something undeclared. */
    #[MCPTool]
    public function probeUndeclaredToolUnderWriteLock(): string
    {
        static::$toolNamesOfRuns[] = __FUNCTION__;
        return static::RESULT_TEXT;
    }
}
