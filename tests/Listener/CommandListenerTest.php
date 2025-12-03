<?php

declare(strict_types=1);

namespace ElasticApmBundle\Tests\Listener;

use ElasticApmBundle\Interactor\Config;
use ElasticApmBundle\Interactor\ElasticApmInteractorInterface;
use ElasticApmBundle\Listener\CommandListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\NullOutput;

#[CoversClass(CommandListener::class)]
final class CommandListenerTest extends TestCase
{
    private \PHPUnit\Framework\MockObject\MockObject $interactor;
    private \PHPUnit\Framework\MockObject\MockObject $config;
    private CommandListener $listener;

    protected function setUp(): void
    {
        $this->interactor = $this->createMock(ElasticApmInteractorInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->listener = new CommandListener($this->interactor, $this->config);
    }

    public function testGetSubscribedEvents(): void
    {
        $events = CommandListener::getSubscribedEvents();

        $this->assertArrayHasKey(ConsoleEvents::COMMAND, $events);
        $this->assertArrayHasKey(ConsoleEvents::ERROR, $events);
        $this->assertSame(['onConsoleCommand', 0], $events[ConsoleEvents::COMMAND]);
        $this->assertSame(['onConsoleError', 0], $events[ConsoleEvents::ERROR]);
    }

    public function testOnConsoleCommandSetsTransactionName(): void
    {
        $command = new Command('test:command');
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $event = new ConsoleCommandEvent($command, $input, $output);

        $this->interactor->expects($this->once())
            ->method('setTransactionName')
            ->with('test:command')
        ;

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig')
        ;

        $this->listener->onConsoleCommand($event);
    }

    public function testOnConsoleCommandAddsOptionsAsContext(): void
    {
        $command = new Command('test:command');
        $command->addOption('verbose');
        $command->addOption('env');

        $input = new ArrayInput(['--verbose' => true, '--env' => 'test']);
        $input->bind($definition = $command->getDefinition());
        $output = new NullOutput();
        $event = new ConsoleCommandEvent($command, $input, $output);

        $this->interactor->expects($this->once())
            ->method('setTransactionName')
        ;

        $this->interactor->expects($this->exactly(2))
            ->method('addCustomContext')
            ->willReturnCallback(
                function ($key, $value) {
                    $this->assertContains($key, ['--verbose', '--env']);

                    return true;
                },
            )
        ;

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig')
        ;

        $this->listener->onConsoleCommand($event);
    }

    public function testOnConsoleCommandAddsArgumentsAsContext(): void
    {
        $command = new Command('test:command');
        $command->addArgument('name', InputArgument::REQUIRED);

        $input = new ArrayInput(['name' => 'test-value']);
        $input->bind($command->getDefinition());

        $output = new NullOutput();
        $event = new ConsoleCommandEvent($command, $input, $output);

        $this->interactor->expects($this->once())
            ->method('setTransactionName')
        ;

        $this->interactor->expects($this->atLeastOnce())
            ->method('addCustomContext')
        ;

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig')
        ;

        $this->listener->onConsoleCommand($event);
    }

    public function testOnConsoleErrorNoticesThrowableWhenEnabled(): void
    {
        $command = new Command('test:command');
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $error = new \RuntimeException('Test error');
        $event = new ConsoleErrorEvent($input, $output, $error, $command);

        $this->config->expects($this->once())
            ->method('shouldExplicitlyCollectCommandExceptions')
            ->willReturn(true)
        ;

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig')
        ;

        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($error)
        ;

        $this->listener->onConsoleError($event);
    }

    public function testOnConsoleErrorDoesNotNoticeThrowableWhenDisabled(): void
    {
        $command = new Command('test:command');
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $error = new \RuntimeException('Test error');
        $event = new ConsoleErrorEvent($input, $output, $error, $command);

        $this->config->expects($this->once())
            ->method('shouldExplicitlyCollectCommandExceptions')
            ->willReturn(false)
        ;

        $this->interactor->expects($this->never())
            ->method('noticeThrowable')
        ;

        $this->listener->onConsoleError($event);
    }

    public function testOnConsoleErrorNoticesPreviousException(): void
    {
        $command = new Command('test:command');
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $previous = new \RuntimeException('Previous error');
        $error = new \RuntimeException('Test error', 0, $previous);
        $event = new ConsoleErrorEvent($input, $output, $error, $command);

        $this->config->expects($this->once())
            ->method('shouldExplicitlyCollectCommandExceptions')
            ->willReturn(true)
        ;

        $this->interactor->expects($this->exactly(2))
            ->method('addContextFromConfig')
        ;

        $this->interactor->expects($this->exactly(2))
            ->method('noticeThrowable')
            ->willReturnCallback(
                function ($throwable) use ($error, $previous): void {
                    $this->assertContains($throwable, [$error, $previous]);
                },
            )
        ;

        $this->listener->onConsoleError($event);
    }
}
