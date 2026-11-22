<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Infrastructure\Libs\Config;

/**
 * Reads the web research configuration (`AI.webSearch`, `config/app/AI/webSearch.php`) as ONE array and looks a dotted
 * path up inside it, so a `false`, `0` or `null` value is read as it is written (a single `Config::get()` of a falsy
 * leaf goes back to the file on every read).
 */
class WebSearchConfiguration
{
    /** The value at `$dottedPath` below `AI.webSearch` (`hits.maxQueriesPerCall`), `$default` when it is not set. */
    public static function findValue(string $dottedPath, mixed $default = null): mixed
    {
        $currentValue = Config::get('AI.webSearch');
        foreach (explode('.', $dottedPath) as $pathSegment) {
            if (!is_array($currentValue) || !array_key_exists($pathSegment, $currentValue)) {
                return $default;
            }
            $currentValue = $currentValue[$pathSegment];
        }
        return $currentValue ?? $default;
    }
}
