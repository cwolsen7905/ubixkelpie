<?php

declare(strict_types=1);

namespace Kelpie\Console\Command;

use Kelpie\DataTransferObject\RunResult;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Output\OutputInterface as Output;

/**
 * Base of the Kelpie commands that hand their run to a service: prints its result and picks the exit code
 *
 * Does not extend the uBixCore command base: that base requires a `Ubix\Console\Command\` class name,
 * so a host command cannot use it. Discovery only needs a Symfony command.
 */
abstract class AbstractRunCommand extends SymfonyCommand
{
    /**
     * Constructor
     *
     * @param Logger $logger Logger
     * @param string $name   The command name
     */
    public function __construct(
        private readonly Logger $logger,
        string $name,
    ) {
        parent::__construct($name);
    }

    /**
     * An option as a string, or null when it was not given
     *
     * @param Input  $input The input
     * @param string $name  The option name
     *
     * @return ?string
     */
    protected function optionOrNull(Input $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : null;
    }

    /**
     * An argument as a string; anything else is an empty string, which every service refuses
     *
     * @param Input  $input The input
     * @param string $name  The argument name
     *
     * @return string
     */
    protected function argumentString(Input $input, string $name): string
    {
        $value = $input->getArgument($name);

        return is_string($value) ? $value : '';
    }

    /**
     * Print a service's result and return the exit code
     *
     * @param Output    $output The output
     * @param RunResult $result The result
     *
     * @return int
     */
    protected function report(Output $output, RunResult $result): int
    {
        $this->logger->info('Command finished', ['command' => $this->getName(), 'success' => $result->success]);

        foreach ($result->lines as $line) {
            $output->writeln($result->isError ? '<error>' . $line . '</error>' : $line);
        }

        return $result->success ? SymfonyCommand::SUCCESS : SymfonyCommand::FAILURE;
    }
}
