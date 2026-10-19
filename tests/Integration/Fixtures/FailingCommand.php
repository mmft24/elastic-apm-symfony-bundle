<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Integration\Fixtures;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class FailingCommand extends Command
{
    public function __construct()
    {
        parent::__construct('test:fail');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        throw new \RuntimeException('Command failed', 0, new \LogicException('Command root cause'));
    }
}
