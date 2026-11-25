<?php

declare(strict_types=1);

/**
 * Stands in for the console script the clear-cache command starts its container cache clear child with: it prints
 * the arguments it got on the standard output, one line on the error output, and exits with the code of the
 * environment variable `AGENTS_TEST_CHILD_EXIT_CODE` (0 when it is not set) — inherited from the parent process.
 */

fwrite(STDOUT, 'child standard output: ' . implode(' ', array_slice($argv, 1)) . PHP_EOL);
fwrite(STDERR, 'child error output' . PHP_EOL);
exit((int)(getenv('AGENTS_TEST_CHILD_EXIT_CODE') ?: 0));
