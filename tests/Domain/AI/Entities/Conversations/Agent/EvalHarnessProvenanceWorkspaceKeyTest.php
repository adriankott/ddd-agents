<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A harness call of an evaluation runs in the workspace of the case run: the key passed, else the one of the judged
 * conversation; null outside a case run.
 */
class EvalHarnessProvenanceWorkspaceKeyTest extends UnitTestCase
{
    public function testAHarnessCallCarriesTheWorkspaceOfTheCaseRun(): void
    {
        /** @var AIConversation $conversation */
        $conversation = self::instantiateWithoutConstructor(AIConversation::class);
        $conversation->runSettings = new RunSettings();
        $conversation->runSettings->workspaceKey = 'eval-case-r1-0a1b2c3d';

        self::assertSame('eval-case-r1-0a1b2c3d', AgentToolCallProvenance::forEvalHarness(null, $conversation)->workspaceKey);
        self::assertSame('eval-other-r2-ffffffff', AgentToolCallProvenance::forEvalHarness(null, $conversation, 'eval-other-r2-ffffffff')->workspaceKey);
        self::assertSame('eval-case-r1-0a1b2c3d', AgentToolCallProvenance::forEvalHarness(null, null, 'eval-case-r1-0a1b2c3d')->workspaceKey);
        self::assertNull(AgentToolCallProvenance::forEvalHarness(null)->workspaceKey);
        self::assertTrue(AgentToolCallProvenance::forEvalHarness(null)->isEvalHarnessCall);
    }
}
