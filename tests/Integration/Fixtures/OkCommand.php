<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Integration\Fixtures;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class OkCommand extends Command
{
    public function __construct()
    {
        parent::__construct('test:ok');
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::OPTIONAL)
            ->addOption('password', null, InputOption::VALUE_REQUIRED)
            ->addOption('tag', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY)
            ->addOption('exit-code', null, InputOption::VALUE_REQUIRED, '', '0')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $exitCode = $input->getOption('exit-code');

        return \is_numeric($exitCode) ? (int) $exitCode : 0;
    }
}
