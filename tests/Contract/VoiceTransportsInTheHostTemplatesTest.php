<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Domain\AI\MessageHandlers\AgentMessengerTransports;
use DDD\Domain\AI\MessageHandlers\Voice\AgentVoiceMessengerTransports;
use DDD\Domain\Common\Services\WorkerDefinitionsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The voice transports in the two files a host copies, the Messenger template of the developer skill and
 * `examples/workers.yaml`: as shipped both hold the six agent transports only; with the commented voice lines
 * uncommented the Messenger template holds exactly {@see AgentVoiceMessengerTransports::ALL} beside them and routes
 * exactly {@see AgentVoiceMessengerTransports::ROUTING_BY_MESSAGE_CLASS}, and the worker list has one consumer per voice
 * transport without an unknown key.
 *
 * A voice line is a commented line whose text is configuration: a `voice_…:` transport key, its `dsn:` or
 * `retry_strategy:`, a routing entry to a `voice_…` transport, or a `voice_…` consumer. The prose comments around them
 * stay comments.
 */
class VoiceTransportsInTheHostTemplatesTest extends UnitTestCase
{
    public const string MESSENGER_TEMPLATE = __DIR__ . '/../../.claude/skills/ddd-module-agents-specialist/templates/messenger.yaml';

    public const string EXAMPLE_WORKER_LIST = __DIR__ . '/../../examples/workers.yaml';

    /** A commented voice line: the indentation before `# `, then the configuration text */
    public const string VOICE_LINE_PATTERN = '/^( *)# ((?: {2})?(?:voice_[a-z_]+:\s*$|dsn: \'[^\']*voice_[a-z_]+\'$|retry_strategy: \{.*\}$|\'[^\']+\': voice_[a-z_]+$|- \{transport: voice_[a-z_]+,.*\}$))/m';

    /** The Messenger template has six voice lines (two transports with a DSN and a retry each); its routing two more */
    public const int MESSENGER_TEMPLATE_VOICE_LINES = 8;

    public const int EXAMPLE_WORKER_LIST_VOICE_LINES = 2;

    protected ?string $temporaryWorkerList = null;

    protected function tearDown(): void
    {
        if ($this->temporaryWorkerList !== null && is_file($this->temporaryWorkerList)) {
            unlink($this->temporaryWorkerList);
        }
        parent::tearDown();
    }

    public function testTheMessengerTemplateAsShippedHoldsTheSixAgentTransportsOnly(): void
    {
        $messengerConfiguration = Yaml::parse((string)file_get_contents(static::MESSENGER_TEMPLATE))['framework']['messenger'];

        static::assertSame(static::sortedList(AgentMessengerTransports::ALL), static::sortedList(array_keys($messengerConfiguration['transports'])));
        static::assertCount(6, $messengerConfiguration['transports']);
        static::assertSame(static::sortedMap(AgentMessengerTransports::ROUTING_BY_MESSAGE_CLASS), static::sortedMap($messengerConfiguration['routing']));
    }

    public function testTheMessengerTemplateWithItsVoiceLinesUncommentedHoldsExactlyTheVoiceTransportsAndRouting(): void
    {
        $uncommentedCount = 0;
        $uncommentedTemplate = (string)preg_replace(static::VOICE_LINE_PATTERN, '$1$2', (string)file_get_contents(static::MESSENGER_TEMPLATE), -1, $uncommentedCount);
        $messengerConfiguration = Yaml::parse($uncommentedTemplate)['framework']['messenger'];

        static::assertSame(static::MESSENGER_TEMPLATE_VOICE_LINES, $uncommentedCount);
        static::assertSame(
            static::sortedList([...AgentMessengerTransports::ALL, ...AgentVoiceMessengerTransports::ALL]),
            static::sortedList(array_keys($messengerConfiguration['transports']))
        );
        foreach (AgentVoiceMessengerTransports::ALL as $voiceTransport) {
            static::assertSame('{{MESSENGER_TRANSPORT_DSN}}/' . $voiceTransport, $messengerConfiguration['transports'][$voiceTransport]['dsn']);
            static::assertSame(['dsn', 'retry_strategy'], array_keys($messengerConfiguration['transports'][$voiceTransport]));
        }
        static::assertSame(
            static::sortedMap([...AgentMessengerTransports::ROUTING_BY_MESSAGE_CLASS, ...AgentVoiceMessengerTransports::ROUTING_BY_MESSAGE_CLASS]),
            static::sortedMap($messengerConfiguration['routing'])
        );
    }

    public function testTheWorkerListAsShippedHoldsTheSixAgentConsumersOnly(): void
    {
        $workerDefinitions = static::getWorkerDefinitionsService()->readWorkerDefinitionsFromFile(static::EXAMPLE_WORKER_LIST);

        static::assertSame(static::sortedList(AgentMessengerTransports::ALL), static::sortedList($workerDefinitions->consumers->getTransportNames()));
    }

    public function testTheWorkerListWithItsVoiceLinesUncommentedHasTwoVoiceConsumersWithoutAnUnknownKey(): void
    {
        $uncommentedCount = 0;
        $uncommentedWorkerList = (string)preg_replace(static::VOICE_LINE_PATTERN, '$1$2', (string)file_get_contents(static::EXAMPLE_WORKER_LIST), -1, $uncommentedCount);
        $this->temporaryWorkerList = (string)tempnam(sys_get_temp_dir(), 'voice-worker-list-');
        file_put_contents($this->temporaryWorkerList, $uncommentedWorkerList);

        $workerDefinitions = static::getWorkerDefinitionsService()->readWorkerDefinitionsFromFile($this->temporaryWorkerList);

        static::assertSame(static::EXAMPLE_WORKER_LIST_VOICE_LINES, $uncommentedCount);
        static::assertSame([], $workerDefinitions->duplicateTransports);
        static::assertSame(
            static::sortedList([...AgentMessengerTransports::ALL, ...AgentVoiceMessengerTransports::ALL]),
            static::sortedList($workerDefinitions->consumers->getTransportNames())
        );
        foreach (AgentVoiceMessengerTransports::ALL as $voiceTransport) {
            $voiceConsumer = $workerDefinitions->consumers->getByTransport($voiceTransport);
            static::assertNotNull($voiceConsumer, $voiceTransport . ' has a consumer');
            static::assertSame([], $voiceConsumer->additionalSettings->getElements(), $voiceTransport . ' carries an unknown key');
        }
    }

    protected static function getWorkerDefinitionsService(): WorkerDefinitionsService
    {
        /** @var WorkerDefinitionsService $workerDefinitionsService */
        $workerDefinitionsService = DDDService::instance()->getService(WorkerDefinitionsService::class);
        return $workerDefinitionsService;
    }

    /**
     * @param string[] $names
     * @return string[]
     */
    protected static function sortedList(array $names): array
    {
        sort($names);
        return $names;
    }

    /**
     * @param array<string, string> $routing
     * @return array<string, string>
     */
    protected static function sortedMap(array $routing): array
    {
        ksort($routing);
        return $routing;
    }
}
