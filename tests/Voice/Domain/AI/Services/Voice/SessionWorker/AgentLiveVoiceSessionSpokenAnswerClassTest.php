<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceSpokenAnswer;
use DDD\Domain\Base\Entities\StaticRegistry;

/**
 * The spoken answer of a finished task is built by the application's same-path subclass of
 * {@see AgentVoiceSpokenAnswer} when there is one, so its hooks (the "shown in the chat" sentence per channel type)
 * apply; without one, by the package's class.
 */
class AgentLiveVoiceSessionSpokenAnswerClassTest extends AgentLiveVoiceSessionWorkerTestCase
{
    /** @var array<string, string> */
    protected array $frameworkToAppClassNamesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
    }

    protected function onTearDown(): void
    {
        StaticRegistry::$frameworkToAppClassNames = $this->frameworkToAppClassNamesBefore;
        parent::onTearDown();
    }

    public function testWithoutAnApplicationClassThePackageClassBuildsTheAnswer(): void
    {
        unset(StaticRegistry::$frameworkToAppClassNames[AgentVoiceSpokenAnswer::class]);
        $worker = $this->buildWorker();

        $spokenAnswer = $worker->runWorkerStep('buildSpokenAnswer', static::buildRunningCall(), 'The order is on its way.');

        static::assertSame(AgentVoiceSpokenAnswer::class, $spokenAnswer::class);
        static::assertSame('The order is on its way.', $spokenAnswer->spokenText);
    }

    public function testTheApplicationsSamePathSubclassBuildsTheAnswer(): void
    {
        StaticRegistry::$frameworkToAppClassNames[AgentVoiceSpokenAnswer::class] = ApplicationAgentVoiceSpokenAnswer::class;
        $worker = $this->buildWorker();

        $spokenAnswer = $worker->runWorkerStep('buildSpokenAnswer', static::buildRunningCall(), 'The order is on its way.');

        static::assertSame(ApplicationAgentVoiceSpokenAnswer::class, $spokenAnswer::class);
        static::assertSame('The order is on its way.', $spokenAnswer->spokenText);
    }
}
