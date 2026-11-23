<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Untrusted;

use DDD\Testing\UntrustedInput\UntrustedSinkScanTestCase;

/**
 * The package's sink scan: every raw sink call goes through a helper of DDD\Infrastructure\Untrusted or is listed in
 * config/untrusted-sinks-allowlist.php.
 */
class UntrustedSinkScanTest extends UntrustedSinkScanTestCase
{
    protected function getPackageRootDirectory(): string
    {
        return dirname(__DIR__, 2);
    }
}
