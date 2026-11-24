<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\Statistics\AgentDecisionStatisticsRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\Statistics\AgentDecisionStatisticsSection;
use DDD\Domain\AI\Services\Decisions\AgentDecisionStatisticsService;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The decision statistics over the turn events, read-only, computed by {@see AgentDecisionStatisticsService}. For a
 * site and a window: decisions per mode × arm × day, applied / skipped by reason / fallbacks, the request latency, and
 * for the skill-preload site the agreement of the shadow decisions with the loads the chat model made afterwards in the
 * same turn (pick in the first load = agreement; pick loaded later = "needed later"; pick made and no load at all = miss,
 * split by whether a resident skill's tool answered or no tool ran), the per-skill precision and the top confusions, the
 * floor sweep over the recorded probabilities, and the preload outcomes of the arm that acts. Evaluation traffic is
 * excluded twice: the stored evaluation marker of the event and the configured evaluation accounts. The reconciliation
 * (population = eligible + excluded; eligible = terminal + pending + missing) is printed first. Observations are bounded
 * to the decision's turn (initiator → next initiator) and to `--cutoff` (default now − 30 min); a turn without a
 * terminal row younger than that is censored, an older one is `missing`. `--power` prints the accrual arithmetic from
 * the observed rates.
 *
 * The run works as the configured default account for console work.
 */
#[AsCommand(
    name: 'app:agent:decision-stats',
    aliases: ['app:ai-agent:decision-stats'],
    description: 'Decision statistics over the agent turn events (per site, mode, arm, day; shadow agreement; floor sweep; reconciliation) — read-only'
)]
class AgentDecisionStatsCommand extends AgentConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'The decision site', AgentDecisionModelSettings::SITE_SKILL_PRELOAD)
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Window start (Y-m-d [H:i:s])', (new DateTime())->modify('-7 days')->format('Y-m-d 00:00:00'))
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Window end, exclusive (Y-m-d [H:i:s])', (new DateTime())->format('Y-m-d H:i:s'))
            ->addOption('cutoff', null, InputOption::VALUE_REQUIRED, 'Observation cutoff (Y-m-d H:i:s); default now − 30 min', (new DateTime())->modify('-30 minutes')->format('Y-m-d H:i:s'))
            ->addOption('mode', null, InputOption::VALUE_OPTIONAL, 'Only decisions of this mode (shadow|on)')
            ->addOption('power', null, InputOption::VALUE_NONE, 'Print the accrual / power arithmetic from the observed rates')
            ->addOption('includeEval', null, InputOption::VALUE_NONE, 'Do not exclude eval traffic');
    }

    /** The statistics read as the configured default account for console work. */
    protected function usesDefaultAccountForConsoleWork(): bool
    {
        return true;
    }

    /**
     * Builds the statistics of the request the options describe and prints every section.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int always `SUCCESS`
     */
    protected function executeConsoleWork(InputInterface $input, OutputInterface $output): int
    {
        set_time_limit(600);
        ini_set('memory_limit', '1024M');
        /** @var AgentDecisionStatisticsService $agentDecisionStatisticsService */
        $agentDecisionStatisticsService = DDDService::instance()->getService(AgentDecisionStatisticsService::class);
        $statistics = $agentDecisionStatisticsService->buildStatistics($this->buildStatisticsRequest($input));

        $symfonyStyle = new SymfonyStyle($input, $output);
        $symfonyStyle->title($statistics->title);
        foreach ($statistics->sections as $section) {
            $this->renderSection($symfonyStyle, $output, $section);
        }
        $symfonyStyle->success('done');
        return Command::SUCCESS;
    }

    /**
     * The statistics request of the options.
     *
     * @param InputInterface $input
     * @return AgentDecisionStatisticsRequest
     */
    protected function buildStatisticsRequest(InputInterface $input): AgentDecisionStatisticsRequest
    {
        $statisticsRequest = AgentDecisionStatisticsRequest::newInstance();
        $statisticsRequest->site = (string)$input->getOption('site');
        $statisticsRequest->from = (string)$input->getOption('from');
        $statisticsRequest->to = (string)$input->getOption('to');
        $statisticsRequest->cutoff = (string)$input->getOption('cutoff');
        $statisticsRequest->mode = $input->getOption('mode') !== null ? strtolower((string)$input->getOption('mode')) : null;
        $statisticsRequest->includeEval = (bool)$input->getOption('includeEval');
        $statisticsRequest->withAccrualArithmetic = (bool)$input->getOption('power');
        return $statisticsRequest;
    }

    /**
     * One section as the console shows it: heading, table ("(no rows)" when empty), text lines, note.
     *
     * @param SymfonyStyle $symfonyStyle
     * @param OutputInterface $output
     * @param AgentDecisionStatisticsSection $section
     * @return void
     */
    protected function renderSection(SymfonyStyle $symfonyStyle, OutputInterface $output, AgentDecisionStatisticsSection $section): void
    {
        if ($section->title !== '') {
            $symfonyStyle->section($section->title);
        }
        if ($section->columns !== []) {
            if ($section->rows === []) {
                $output->writeln('  (no rows)');
            } else {
                $table = new Table($output);
                $table->setHeaders($section->columns);
                foreach ($section->rows as $row) {
                    $table->addRow($row->cells);
                }
                $table->render();
            }
        }
        foreach ($section->textLines as $textLine) {
            $symfonyStyle->text($textLine);
        }
        if ($section->note !== null) {
            $symfonyStyle->note($section->note);
        }
    }
}
