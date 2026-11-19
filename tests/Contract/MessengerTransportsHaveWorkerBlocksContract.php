<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Domain\AI\MessageHandlers\AgentMessengerTransports;
use Symfony\Component\Yaml\Yaml;

/**
 * The transport contract an application that runs the agent's workers must meet, checked against ITS configuration:
 * every transport of {@see AgentMessengerTransports::ALL} is defined in the application's `messenger.yaml`, every
 * message of {@see AgentMessengerTransports::ROUTING_BY_MESSAGE_CLASS} is routed to its transport, and the
 * application runs workers for each: a consumer in the framework's worker list (`config/system/workers.yaml`, the list
 * `app:workers:check` compares with the container), or a worker pool in the application's supervisor file
 * (`[program:<transport>]` consuming that transport). Either source covers a transport.
 *
 * Use it in one test class of the application (a trait, so the class keeps the application's own test base):
 * ```php
 * class AgentMessengerTransportsTest extends MyAppTestCase
 * {
 *     use MessengerTransportsHaveWorkerBlocksContract;
 *     protected function messengerConfigPath(): string { return '…/config/packages/messenger.yaml'; }
 * }
 * ```
 * The worker list is found next to the Messenger configuration (`config/packages/messenger.yaml` →
 * `config/system/workers.yaml`); {@see workerListConfigPath()} names another file. An application with a supervisor
 * file overrides {@see supervisorConfigPath()}.
 * The test file is not autoloaded by an application (it is the package's test code): `require_once` it from the
 * package's install directory.
 */
trait MessengerTransportsHaveWorkerBlocksContract
{
    /** @return string The absolute path of the application's Messenger configuration (`framework.messenger.transports` / `routing`) */
    abstract protected function messengerConfigPath(): string;

    /**
     * @return string|null The absolute path of the application's supervisor configuration (one `[program:<name>]` block
     *                     per pool); null = the application has none
     */
    protected function supervisorConfigPath(): ?string
    {
        return null;
    }

    /**
     * @return string|null The absolute path of the application's worker list (the framework's `workers.yaml`: a
     *                     `consumers` list with one `transport` per entry); null = none. Default: `config/system/workers.yaml`
     *                     beside the `config/packages/` folder of {@see messengerConfigPath()}, when that file exists.
     */
    protected function workerListConfigPath(): ?string
    {
        $workerListPath = dirname($this->messengerConfigPath(), 2) . '/system/workers.yaml';
        return is_file($workerListPath) ? $workerListPath : null;
    }

    public function testEveryAgentTransportIsDefined(): void
    {
        $transports = $this->readMessengerConfig()['transports'] ?? [];
        foreach (AgentMessengerTransports::ALL as $transportName) {
            self::assertArrayHasKey($transportName, $transports, "the agent transport '{$transportName}' is not defined in the Messenger configuration");
        }
    }

    public function testEveryAgentMessageIsRoutedToItsTransport(): void
    {
        $routing = $this->readMessengerConfig()['routing'] ?? [];
        foreach (AgentMessengerTransports::ROUTING_BY_MESSAGE_CLASS as $messageClass => $transportName) {
            self::assertArrayHasKey($messageClass, $routing, "the agent message {$messageClass} is not routed");
            $routedTransports = (array)$routing[$messageClass];
            self::assertSame([$transportName], array_values($routedTransports), "the agent message {$messageClass} must go to '{$transportName}' only");
        }
    }

    public function testEveryAgentTransportHasAWorkerPool(): void
    {
        $workerListPath = $this->workerListConfigPath();
        $supervisorPath = $this->supervisorConfigPath();
        self::assertTrue(
            ($workerListPath !== null && is_file($workerListPath)) || ($supervisorPath !== null && is_file($supervisorPath)),
            'the application names neither a worker list (config/system/workers.yaml) nor a supervisor file'
        );
        $transportsOfWorkerList = $workerListPath !== null && is_file($workerListPath) ? $this->readTransportsOfWorkerList($workerListPath) : [];
        $supervisorFile = $supervisorPath !== null && is_file($supervisorPath) ? (string)file_get_contents($supervisorPath) : '';
        preg_match_all('/^\[program:([^\]]+)\]/m', $supervisorFile, $programMatches);
        foreach (AgentMessengerTransports::ALL as $transportName) {
            $hasSupervisorPool = in_array($transportName, $programMatches[1], true)
                && str_contains($supervisorFile, "messenger:consume {$transportName} ");
            self::assertTrue(
                in_array($transportName, $transportsOfWorkerList, true) || $hasSupervisorPool,
                "the agent transport '{$transportName}' has no consumer in the worker list and no [program:{$transportName}] block consuming it in the supervisor file"
            );
        }
    }

    /**
     * The transports the worker list runs workers for: every `consumers` entry that names a transport, is not limited
     * to development (`devOnly`) and expects at least one process (`replicas`, default 1, or an autoscaled `max`).
     *
     * @param string $workerListPath
     * @return string[]
     */
    protected function readTransportsOfWorkerList(string $workerListPath): array
    {
        $workerList = Yaml::parseFile($workerListPath);
        $transportNames = [];
        foreach ((array)($workerList['consumers'] ?? []) as $consumer) {
            if (!is_array($consumer) || !is_string($consumer['transport'] ?? null) || ($consumer['devOnly'] ?? false) === true) {
                continue;
            }
            $highestProcessCount = isset($consumer['max']) ? (int)$consumer['max'] : (int)($consumer['replicas'] ?? 1);
            if ($highestProcessCount > 0) {
                $transportNames[] = $consumer['transport'];
            }
        }
        return $transportNames;
    }

    /**
     * @return array<string, mixed> The `framework.messenger` section of the application's Messenger configuration
     */
    protected function readMessengerConfig(): array
    {
        $messengerConfig = Yaml::parseFile($this->messengerConfigPath(), Yaml::PARSE_CUSTOM_TAGS);
        return $messengerConfig['framework']['messenger'] ?? [];
    }
}
