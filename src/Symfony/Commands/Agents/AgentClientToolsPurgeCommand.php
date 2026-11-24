<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest;
use DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Housekeeping of the client tool rows of `Agent.UI`, run by a cron once per hour: requests whose tool stopped waiting
 * (a worker that died mid-wait) become EXPIRED with their answer text removed, requests older than
 * AgentClientToolRequest::KEPT_FOR_DAYS days and client attachments not seen for a day are deleted
 * ({@see AgentClientToolRequestsService::purge()}). Plain statements: no rights apply.
 *
 *   php bin/console app:agent:purge-client-tool-requests
 */
#[AsCommand(
    name: 'app:agent:purge-client-tool-requests',
    aliases: ['app:ai-agent:client-tools:purge'],
    description: 'Expire overdue UI tool requests and delete old requests and client attachments (Agent.UI).',
)]
class AgentClientToolsPurgeCommand extends AgentConsoleCommand
{
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        /** @var AgentClientToolRequestsService $agentClientToolRequestsService */
        $agentClientToolRequestsService = DDDService::instance()->getService(AgentClientToolRequestsService::class);
        $agentClientToolsPurgeResult = $agentClientToolRequestsService->purge();
        $output->writeln(
            'expired ' . $agentClientToolsPurgeResult->expiredRequestCount . ' overdue request(s), deleted '
            . $agentClientToolsPurgeResult->deletedRequestCount . ' request(s) older than ' . AgentClientToolRequest::KEPT_FOR_DAYS
            . ' days and ' . $agentClientToolsPurgeResult->deletedAttachmentCount . ' old client attachment(s)'
        );
        return Command::SUCCESS;
    }
}
