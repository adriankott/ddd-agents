<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Evals\AgentEvalSuiteRecordSettings;

/**
 * The record settings of an application that names its scope with its own word.
 */
class AgentEvalSuiteRecordSettingsOfAnApplication extends AgentEvalSuiteRecordSettings
{
    /** @var string The application's key of the whole-scope isolation */
    public const string ISOLATE_WHOLE_SCOPE_KEY = 'isolateWorkshops';
}
