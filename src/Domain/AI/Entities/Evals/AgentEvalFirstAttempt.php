<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a first attempt that ended in an infrastructure error leaves on the row of the second attempt (`firstAttempt`):
 * its error (at most {@see ERROR_MAX_LENGTH} characters), its process seconds and its harness log.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalFirstAttempt extends ValueObject
{
    /** @var int The longest error text kept */
    public const int ERROR_MAX_LENGTH = 600;

    /** @var string The error the first attempt ended with, cut to {@see ERROR_MAX_LENGTH} characters */
    public string $error = '';

    /** @var float The wall seconds of the first attempt's child process */
    public float $processSeconds = 0.0;

    /** @var string[] The harness lines of the first attempt */
    public array $harnessLog = [];

    /** What the row `$firstAttemptRow` of the first attempt leaves. */
    public static function fromRow(AgentEvalCaseRow $firstAttemptRow): static
    {
        $agentEvalFirstAttempt = static::newInstance();
        $agentEvalFirstAttempt->error = mb_substr((string)$firstAttemptRow->getValue('error'), 0, static::ERROR_MAX_LENGTH);
        $agentEvalFirstAttempt->processSeconds = $firstAttemptRow->getProcessSeconds();
        $agentEvalFirstAttempt->harnessLog = (array)($firstAttemptRow->getValue('harnessLog') ?? []);
        return $agentEvalFirstAttempt;
    }

    /**
     * The block as the row of the second attempt stores it under `firstAttempt`: part of the stored document of
     * `<model>-<flavor>/<suite>-<caseId>.json`.
     *
     * @return array{error: string, processSeconds: float, harnessLog: array}
     */
    public function toArray(): array
    {
        return ['error' => $this->error, 'processSeconds' => $this->processSeconds, 'harnessLog' => $this->harnessLog];
    }
}
