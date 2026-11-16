<?php

declare(strict_types=1);

/**
 * The evaluation suite's files and its schedule — read as `Config::get('AI.evalSuite.<key>')`. An application replaces
 * this file whole with its own `config/app/AI/evalSuite.php` (keys are never merged), so its file names every key.
 *
 * `resultsDirectory`: the folder every evaluation run writes its files to (one folder or one file per run, the case
 *   files, the run summaries, the suite record), read by
 *   {@see \DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService::getResultsDirectory()}. A relative path is taken
 *   relative to the kernel project directory; an absolute path is taken as it is. The retention sweep of that service
 *   deletes only the dated run entries it recognises in this folder, so the folder may hold other files of the
 *   application.
 * `resourceGroupByFixture`: fixture name => resource group. Write cases of fixtures in one group change the same
 *   resource of an agent space and never run at the same time on it; a fixture not listed is its own resource (the
 *   key is its name). Empty in the package.
 * `exclusiveFixtures`: the fixtures whose write cases change state the behaviour of other cases depends on; such a
 *   case runs alone on its agent space. Empty in the package.
 * `suites`: suite name => `['folder' => <fixture folder>, 'kind' => <task kind>, 'default' => <bool>, 'flavors' =>
 *   <string[]>]` — the suites a suite run can plan, read by
 *   {@see \DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService::getSuiteDefinitions()}. `kind` is a task kind
 *   (`toolsCase`, `selectionCase`, `recallCase` or an application's own); `default` (true when absent) says whether a
 *   run without a suite list takes the suite; `flavors` (every flavor when absent) names the flavors it runs in. A
 *   relative folder is taken relative to the kernel project directory. Empty in the package: it ships no fixtures.
 * `fixturePaths`: name => the default fixture of a single evaluation command, read by
 *   {@see \DDD\Domain\AI\Services\Eval\AgentEvalSuitePlanService::findFixturePath()}; relative to the kernel project
 *   directory unless absolute. `selection` names one fixture FILE (the selection evaluation reads one file); `tools`,
 *   `recall`, `guard` name a file or a folder of fixture files; `eviction` names one file. Empty in the package.
 */
return [
    'resultsDirectory' => 'var/agent-evals',
    'resourceGroupByFixture' => [],
    'exclusiveFixtures' => [],
    'suites' => [],
    'fixturePaths' => [],
];
