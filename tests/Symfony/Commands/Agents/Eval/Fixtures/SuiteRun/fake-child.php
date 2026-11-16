<?php

declare(strict_types=1);

/*
 * A stand-in for the evaluation command a suite starts per case: it reads its case from the fixture (`--fixture`,
 * `--case`) and plays the case's `childScript` block — the result row it writes to `--result-file` (`cases[0]`), the
 * lines of its error output, its exit code; `firstAttemptRow` is written instead on the first attempt only (a marker
 * file beside the result file remembers it). Without a row it writes no result file. Every argument it got is kept in
 * `<result file>.arguments.json` for the test to read.
 */

$argumentValues = [];
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $argument, $argumentParts) === 1) {
        $argumentValues[$argumentParts[1]] = $argumentParts[2];
    }
}
$resultFile = (string)($argumentValues['result-file'] ?? '');
$caseId = (string)($argumentValues['case'] ?? '');
$fixture = json_decode((string)file_get_contents((string)($argumentValues['fixture'] ?? '')), true);
$childScript = [];
foreach ((array)($fixture['cases'] ?? []) as $fixtureCase) {
    if (($fixtureCase['id'] ?? null) === $caseId) {
        $childScript = (array)($fixtureCase['childScript'] ?? []);
    }
}
file_put_contents($resultFile . '.arguments.json', (string)json_encode(array_slice($argv, 1), JSON_UNESCAPED_SLASHES));
$firstAttemptMarker = $resultFile . '.first-attempt-done';
$row = $childScript['row'] ?? null;
if (isset($childScript['firstAttemptRow']) && !is_file($firstAttemptMarker)) {
    touch($firstAttemptMarker);
    $row = $childScript['firstAttemptRow'];
}
if (is_array($row)) {
    file_put_contents($resultFile, (string)json_encode(['cases' => [$row]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
fwrite(STDERR, (string)($childScript['stderr'] ?? ''));
exit((int)($childScript['exitCode'] ?? 0));
