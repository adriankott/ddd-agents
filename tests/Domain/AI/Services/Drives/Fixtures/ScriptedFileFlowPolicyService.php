<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Drives\Fixtures;

use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowDecision;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowTarget;
use DDD\Domain\Common\Services\Files\FileFlowPolicyService;

/** A flow rule answering the decision of the test, else Core's default */
class ScriptedFileFlowPolicyService extends FileFlowPolicyService
{
    public ?FileFlowDecision $fileFlowDecision = null;

    /** @var string[] The kinds of the targets asked */
    public array $askedTargetKinds = [];

    public function decideFileFlow(FileFacts $fileFacts, FileFlowTarget $fileFlowTarget): FileFlowDecision
    {
        $this->askedTargetKinds[] = $fileFlowTarget->kind;
        return $this->fileFlowDecision ?? parent::decideFileFlow($fileFacts, $fileFlowTarget);
    }
}
