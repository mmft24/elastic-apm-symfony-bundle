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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;

#[CoversClass(CommandListener::class)]
final class CommandListenerTest extends TestCase
{
    private ElasticApmInteractorInterface&\PHPUnit\Framework\MockObject\MockObject $interactor;
    private Config&\PHPUnit\Framework\MockObject\Stub $config;

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
        $listener = $this->createListener();
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

        $listener->onConsoleCommand($event);
    }

    public function testOnConsoleCommandAddsOptionsAsContext(): void
    {
        $listener = $this->createListener();
        $command = new Command('test:command');
        $command->addOption('verbose');
        $command->addOption('env');

        $input = new ArrayInput(['--verbose' => true, '--env' => 'test']);
        $input->bind($command->getDefinition());
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

        $listener->onConsoleCommand($event);
    }

    public function testOnConsoleCommandAddsArgumentsAsContext(): void
    {
        $listener = $this->createListener();
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

        $listener->onConsoleCommand($event);
    }

    public function testOnConsoleCommandWithNullCommandDoesNotCrash(): void
    {
        $listener = $this->createListener();
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $event = new ConsoleCommandEvent(null, $input, $output);

        $this->interactor->expects($this->once())
            ->method('setTransactionName')
            ->with('unknown command')
        ;

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig')
        ;

        $listener->onConsoleCommand($event);
    }

    public function testOnConsoleCommandWithUnnamedCommandDoesNotCrash(): void
    {
        $listener = $this->createListener();
        $command = new Command();
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $event = new ConsoleCommandEvent($command, $input, $output);

        $this->interactor->expects($this->once())
            ->method('setTransactionName')
            ->with('unknown command')
        ;

        $listener->onConsoleCommand($event);
    }

    public function testOnConsoleCommandRedactsSensitiveOptions(): void
    {
        $listener = $this->createListener();
        $command = new Command('test:command');
        $command->addOption('password', null, InputOption::VALUE_REQUIRED);
        $command->addOption('env', null, InputOption::VALUE_REQUIRED);

        $input = new ArrayInput(['--password' => 'hunter2', '--env' => 'prod']);
        $input->bind($command->getDefinition());
        $output = new NullOutput();
        $event = new ConsoleCommandEvent($command, $input, $output);

        $this->interactor->expects($this->once())->method('setTransactionName');
        $this->interactor->expects($this->once())->method('addContextFromConfig');

        $received = [];
        $this->interactor->method('addCustomContext')
            ->willReturnCallback(function ($key, $value) use (&$received) {
                $received[$key] = $value;

                return true;
            })
        ;

        $listener->onConsoleCommand($event);

        $this->assertSame('[REDACTED]', $received['--password'] ?? null);
        $this->assertSame('prod', $received['--env'] ?? null);
    }

    public function testOnConsoleCommandRedactsSensitiveArguments(): void
    {
        $listener = $this->createListener();
        $command = new Command('test:command');
        $command->addArgument('token', InputArgument::REQUIRED);

        $input = new ArrayInput(['token' => 'super-secret-value']);
        $input->bind($command->getDefinition());
        $output = new NullOutput();
        $event = new ConsoleCommandEvent($command, $input, $output);

        $this->interactor->expects($this->once())->method('setTransactionName');
        $this->interactor->expects($this->once())->method('addContextFromConfig');

        $received = [];
        $this->interactor->method('addCustomContext')
            ->willReturnCallback(function ($key, $value) use (&$received) {
                $received[$key] = $value;

                return true;
            })
        ;

        $listener->onConsoleCommand($event);

        $this->assertSame('[REDACTED]', $received['token'] ?? null);
        $this->assertStringNotContainsString('super-secret-value', \implode(' ', \array_map(\strval(...), $received)));
    }

    public function testOnConsoleCommandRedactsTokenMatchedNames(): void
    {
        $listener = $this->createListener();
        // Names that a bare-substring denylist missed (no "password"/"token"/"auth" substring) but which
        // tokenize to a sensitive word must now be redacted.
        $command = new Command('test:command');
        $command->addOption('ssh-key', null, InputOption::VALUE_REQUIRED);
        $command->addOption('passphrase', null, InputOption::VALUE_REQUIRED);
        $command->addOption('oauth-token', null, InputOption::VALUE_REQUIRED);

        $input = new ArrayInput([
            '--ssh-key' => 'id_rsa',
            '--passphrase' => 'letmein',
            '--oauth-token' => 'abc123',
        ]);
        $input->bind($command->getDefinition());
        $output = new NullOutput();
        $event = new ConsoleCommandEvent($command, $input, $output);

        $this->interactor->expects($this->once())->method('setTransactionName');
        $this->interactor->expects($this->once())->method('addContextFromConfig');

        $received = [];
        $this->interactor->method('addCustomContext')
            ->willReturnCallback(function ($key, $value) use (&$received) {
                $received[$key] = $value;

                return true;
            })
        ;

        $listener->onConsoleCommand($event);

        $this->assertSame('[REDACTED]', $received['--ssh-key'] ?? null);
        $this->assertSame('[REDACTED]', $received['--passphrase'] ?? null);
        $this->assertSame('[REDACTED]', $received['--oauth-token'] ?? null);
    }

    public function testOnConsoleCommandDoesNotRedactNonSensitiveLookalikes(): void
    {
        $listener = $this->createListener();
        // "author"/"authority" contain the substring "auth" but are not sensitive: token matching must
        // record their values verbatim.
        $command = new Command('test:command');
        $command->addOption('author', null, InputOption::VALUE_REQUIRED);
        $command->addOption('authority', null, InputOption::VALUE_REQUIRED);

        $input = new ArrayInput(['--author' => 'jane', '--authority' => 'example.com']);
        $input->bind($command->getDefinition());
        $output = new NullOutput();
        $event = new ConsoleCommandEvent($command, $input, $output);

        $this->interactor->expects($this->once())->method('setTransactionName');
        $this->interactor->expects($this->once())->method('addContextFromConfig');

        $received = [];
        $this->interactor->method('addCustomContext')
            ->willReturnCallback(function ($key, $value) use (&$received) {
                $received[$key] = $value;

                return true;
            })
        ;

        $listener->onConsoleCommand($event);

        $this->assertSame('jane', $received['--author'] ?? null);
        $this->assertSame('example.com', $received['--authority'] ?? null);
    }

    public function testOnConsoleCommandRedactsAdditionalConfiguredNames(): void
    {
        $listener = $this->createListener(['customer-pin']);

        $command = new Command('test:command');
        $command->addOption('customer-pin', null, InputOption::VALUE_REQUIRED);
        $command->addOption('env', null, InputOption::VALUE_REQUIRED);

        $input = new ArrayInput(['--customer-pin' => '1234', '--env' => 'prod']);
        $input->bind($command->getDefinition());
        $output = new NullOutput();
        $event = new ConsoleCommandEvent($command, $input, $output);

        $this->interactor->expects($this->once())->method('setTransactionName');
        $this->interactor->expects($this->once())->method('addContextFromConfig');

        $received = [];
        $this->interactor->method('addCustomContext')
            ->willReturnCallback(function ($key, $value) use (&$received) {
                $received[$key] = $value;

                return true;
            })
        ;

        $listener->onConsoleCommand($event);

        $this->assertSame('[REDACTED]', $received['--customer-pin'] ?? null);
        $this->assertSame('prod', $received['--env'] ?? null);
    }

    public function testOnConsoleErrorNoticesThrowableWhenEnabled(): void
    {
        $listener = $this->createListener();
        $command = new Command('test:command');
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $error = new \RuntimeException('Test error');
        $event = new ConsoleErrorEvent($input, $output, $error, $command);

        $this->config->method('shouldExplicitlyCollectCommandExceptions')
            ->willReturn(true)
        ;

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig')
        ;

        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($error)
        ;

        $listener->onConsoleError($event);
    }

    public function testOnConsoleErrorDoesNotNoticeThrowableWhenDisabled(): void
    {
        $listener = $this->createListener();
        $command = new Command('test:command');
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $error = new \RuntimeException('Test error');
        $event = new ConsoleErrorEvent($input, $output, $error, $command);

        $this->config->method('shouldExplicitlyCollectCommandExceptions')
            ->willReturn(false)
        ;

        $this->interactor->expects($this->never())
            ->method('noticeThrowable')
        ;

        $listener->onConsoleError($event);
    }

    public function testOnConsoleErrorReportsErrorOnceLeavingUnwrappingToInteractor(): void
    {
        $listener = $this->createListener();
        // The listener must not walk the getPrevious() chain itself: noticeThrowable() already unwraps the
        // whole chain when unwrapping is enabled, so reporting the cause here too would double-report it.
        $command = new Command('test:command');
        $input = new ArrayInput([]);
        $output = new NullOutput();
        $previous = new \RuntimeException('Previous error');
        $error = new \RuntimeException('Test error', 0, $previous);
        $event = new ConsoleErrorEvent($input, $output, $error, $command);

        $this->config->method('shouldExplicitlyCollectCommandExceptions')
            ->willReturn(true)
        ;

        $this->interactor->expects($this->once())
            ->method('addContextFromConfig')
        ;

        $this->interactor->expects($this->once())
            ->method('noticeThrowable')
            ->with($error)
        ;

        $listener->onConsoleError($event);
    }

    /**
     * @param list<string> $additionalSensitiveNames
     */
    private function createListener(array $additionalSensitiveNames = []): CommandListener
    {
        $this->interactor = $this->createMock(ElasticApmInteractorInterface::class);
        $this->config = $this->createStub(Config::class);

        return new CommandListener($this->interactor, $this->config, $additionalSensitiveNames);
    }
}
