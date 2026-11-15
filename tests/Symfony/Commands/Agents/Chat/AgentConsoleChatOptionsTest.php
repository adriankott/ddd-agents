<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\Chat;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatRecordedRun;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatRenderOptions;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatRunOptions;
use DDD\Symfony\Commands\Agents\Chat\AgentConsoleChatScope;
use DDD\Tests\Agents\Support\UnitTestCase;
use DDD\Tests\Agents\Symfony\Commands\Agents\RecordingAgentRecordedRunSessionDouble;
use Symfony\Component\Console\Command\Command;

/**
 * The plain objects of the console chat: the copies the render and run options make keep every other field, a scope
 * is either an account or a refusal, a recorded run either a session or a refusal.
 */
class AgentConsoleChatOptionsTest extends UnitTestCase
{
    public function testSeveralTurnsPrintTruncatedWithoutTranscriptAndKeepTheBlocksAfterATurn(): void
    {
        $renderOptions = new AgentConsoleChatRenderOptions(true, true, true, true, true, true, true, '/tmp/transcript.html', false);

        $severalTurnsRenderOptions = $renderOptions->forSeveralTurns();

        self::assertFalse($severalTurnsRenderOptions->debug);
        self::assertFalse($severalTurnsRenderOptions->full);
        self::assertFalse($severalTurnsRenderOptions->collapseThreads);
        self::assertNull($severalTurnsRenderOptions->htmlPath);
        self::assertTrue($severalTurnsRenderOptions->echoUserMessage);
        self::assertTrue($severalTurnsRenderOptions->timings);
        self::assertTrue($severalTurnsRenderOptions->showCapabilities);
        self::assertTrue($severalTurnsRenderOptions->showContextComposition);
        self::assertTrue($severalTurnsRenderOptions->showMemoryCuration);
    }

    public function testTheEchoCopyChangesOnlyTheEcho(): void
    {
        $renderOptions = new AgentConsoleChatRenderOptions(true, false, true, false, true, false, true, '/tmp/transcript.html', true);

        $withoutEcho = $renderOptions->withEchoOfUserMessage(false);

        self::assertFalse($withoutEcho->echoUserMessage);
        self::assertEquals(new AgentConsoleChatRenderOptions(true, false, true, false, true, false, true, '/tmp/transcript.html', false), $withoutEcho);
        self::assertTrue($renderOptions->echoUserMessage, 'the original is unchanged');
    }

    public function testTheAsyncCopyChangesOnlyTheWorkerPath(): void
    {
        $runOptions = new AgentConsoleChatRunOptions(false, 'VENDOR.SOME_MODEL', true, false, false);

        self::assertEquals(new AgentConsoleChatRunOptions(false, 'VENDOR.SOME_MODEL', false, false, false), $runOptions->withAsync(false));
    }

    public function testAScopeIsAnAccountOrARefusal(): void
    {
        $account = Account::newInstance();
        $account->id = 3;

        self::assertFalse(AgentConsoleChatScope::forAccountInAgentSpace($account, null)->isRefused());
        $refusedScope = AgentConsoleChatScope::refused('Account 4 not found', Command::FAILURE);
        self::assertTrue($refusedScope->isRefused());
        self::assertSame(Command::FAILURE, $refusedScope->refusalExitCode);
    }

    public function testARecordedRunIsASessionOrARefusal(): void
    {
        $recordedRun = AgentConsoleChatRecordedRun::forSession(new RecordingAgentRecordedRunSessionDouble(), [2 => 'one', 5 => 'two'], true);

        self::assertFalse($recordedRun->isRefused());
        self::assertSame(['one', 'two'], $recordedRun->turns, 'the turns are numbered from the first');
        self::assertTrue(AgentConsoleChatRecordedRun::refused('unreadable')->isRefused());
    }
}
