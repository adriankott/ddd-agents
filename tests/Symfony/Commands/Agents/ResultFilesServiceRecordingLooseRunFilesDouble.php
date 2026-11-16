<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Services\Eval\AgentEvalResultFilesService;

/**
 * A result files service that writes no file: it records the loose run file it was asked to write, encoded as it
 * would be written.
 */
class ResultFilesServiceRecordingLooseRunFilesDouble extends AgentEvalResultFilesService
{
    public const string WRITTEN_FILE = '/results/run-file.json';

    public ?string $modelName = null;

    public string $runFileName = '';

    public string $encodedDocument = '';

    public function writeLooseRunFile(?string $modelName, string $runFileName, array $document, int $jsonFlags = self::JSON_FLAGS): string
    {
        $this->modelName = $modelName;
        $this->runFileName = $runFileName;
        $this->encodedDocument = (string)json_encode($document, $jsonFlags);
        return self::WRITTEN_FILE;
    }
}
