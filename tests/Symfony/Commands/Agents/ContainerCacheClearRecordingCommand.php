<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** A console command under the name of the container cache clear that only counts its runs. */
class ContainerCacheClearRecordingCommand extends Command
{
    public int $runCount = 0;

    public function __construct()
    {
        parent::__construct('cache:clear');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->runCount++;
        $output->writeln('container cache cleared');
        return Command::SUCCESS;
    }
}
