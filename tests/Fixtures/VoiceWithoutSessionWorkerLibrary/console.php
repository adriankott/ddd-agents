<?php

declare(strict_types=1);

/**
 * The console of the kernel that `kernel-without-session-worker-library.php` builds: the voice channel switched on,
 * the WebSocket library of the session worker not installed.
 */

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;

$kernel = require __DIR__ . '/kernel-without-session-worker-library.php';
exit((new Application($kernel))->run(new ArgvInput()));
