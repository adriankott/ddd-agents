<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFindings;
use DDD\Domain\AI\Services\Installation\AgentInstallationCheckService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Checks how the application installed the agent packages ({@see AgentInstallationCheckService}): unbound package
 * skills, bindings to missing classes, rights held by nobody, skills that do not name their packages' rights, missing
 * tables, renamed config keys still set, package commands the console does not resolve, the skill lint, pending
 * renames, and every check another package adds. Read-only. Exit 1 when a finding is BLOCKING, else 0.
 *
 *   php bin/console app:agent:check-installation
 *   php bin/console app:agent:check-installation --format=json
 */
#[AsCommand(
    name: 'app:agent:check-installation',
    description: 'Check the installation of the agent packages (bindings, rights, tables, config keys, commands, skill lint, pending renames); read-only, exit 1 on a BLOCKING finding.',
)]
class AgentCheckInstallationCommand extends AgentConsoleCommand
{
    public const string OPTION_FORMAT = 'format';

    public const string FORMAT_TEXT = 'text';

    public const string FORMAT_JSON = 'json';

    protected function configure(): void
    {
        $this->addOption(static::OPTION_FORMAT, null, InputOption::VALUE_REQUIRED, 'text or json', static::FORMAT_TEXT);
    }

    protected function usesDefaultAccountForConsoleWork(): bool
    {
        return true;
    }

    /**
     * The default account for console work, or null when it cannot be read: the check runs also against a database
     * that does not answer (the table check then reports it as a finding). The database is probed first with the
     * check's short connect timeout ({@see AgentInstallationCheckService::findDatabaseProbe()}); when the probe gets
     * no answer the account is not read, so the application's connection never waits for its own connect timeout.
     *
     * @return Account|null
     */
    protected function findDefaultAccountForConsoleWork(): ?Account
    {
        /** @var AgentInstallationCheckService $agentInstallationCheckService */
        $agentInstallationCheckService = DDDService::instance()->getService(AgentInstallationCheckService::class);
        if (!$agentInstallationCheckService->findDatabaseProbe()->answered) {
            return null;
        }
        try {
            return parent::findDefaultAccountForConsoleWork();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Runs the checks and prints the findings.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int `Command::FAILURE` when a finding is BLOCKING, `Command::INVALID` for an unknown format
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        $outputFormat = (string)$input->getOption(static::OPTION_FORMAT);
        if (!in_array($outputFormat, [static::FORMAT_TEXT, static::FORMAT_JSON], true)) {
            $output->writeln("<error>--format must be text or json, not '$outputFormat'</error>");
            return Command::INVALID;
        }
        /** @var AgentInstallationCheckService $agentInstallationCheckService */
        $agentInstallationCheckService = DDDService::instance()->getService(AgentInstallationCheckService::class);
        $agentInstallationFindings = $agentInstallationCheckService->findInstallationFindings($this->getApplication());
        if ($outputFormat === static::FORMAT_JSON) {
            $output->writeln((string)json_encode($this->buildJsonDocument($agentInstallationFindings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->printFindingsAsText($agentInstallationFindings, $output);
        }
        return $agentInstallationFindings->hasBlockingFindings() ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * The JSON document: `ok` (no BLOCKING finding), `blocking`, `warnings`, then one object per finding.
     *
     * @return array<string, mixed>
     */
    protected function buildJsonDocument(AgentInstallationFindings $agentInstallationFindings): array
    {
        $findingObjects = [];
        foreach ($agentInstallationFindings->getElements() as $agentInstallationFinding) {
            $findingObjects[] = [
                'checkName' => $agentInstallationFinding->checkName,
                'severity' => $agentInstallationFinding->severity,
                'subject' => $agentInstallationFinding->subject,
                'message' => $agentInstallationFinding->message,
                'hint' => $agentInstallationFinding->hint,
            ];
        }
        return [
            'ok' => !$agentInstallationFindings->hasBlockingFindings(),
            'blocking' => $agentInstallationFindings->countBlockingFindings(),
            'warnings' => $agentInstallationFindings->countWarnings(),
            'findings' => $findingObjects,
        ];
    }

    protected function printFindingsAsText(AgentInstallationFindings $agentInstallationFindings, OutputInterface $output): void
    {
        if ($agentInstallationFindings->count() === 0) {
            $output->writeln('<info>OK: no finding.</info>');
            return;
        }
        $findingsTable = new Table($output);
        $findingsTable->setHeaders(['Severity', 'Check', 'Subject', 'Finding', 'Hint']);
        foreach ($agentInstallationFindings->getElements() as $agentInstallationFinding) {
            $findingsTable->addRow([
                $agentInstallationFinding->isBlocking() ? '<error>BLOCKING</error>' : '<comment>WARNING</comment>',
                $agentInstallationFinding->checkName,
                $agentInstallationFinding->subject,
                $agentInstallationFinding->message,
                $agentInstallationFinding->hint ?? '',
            ]);
        }
        $findingsTable->setColumnMaxWidth(2, 40);
        $findingsTable->setColumnMaxWidth(3, 70);
        $findingsTable->setColumnMaxWidth(4, 60);
        $findingsTable->render();
        $output->writeln(sprintf(
            '%s: %d blocking, %d warning(s).',
            $agentInstallationFindings->hasBlockingFindings() ? '<error>BLOCKING</error>' : '<info>OK</info>',
            $agentInstallationFindings->countBlockingFindings(),
            $agentInstallationFindings->countWarnings()
        ));
    }
}
