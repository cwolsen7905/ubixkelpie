<?php

declare(strict_types=1);

namespace Kelpie\Console\Command;

use Kelpie\Console\Command\AbstractRunCommand as RunCommand;
use Kelpie\Sandbox\GateServer;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as Output;

/**
 * The gate pod's entry point: serve gate runs one at a time, resetting the container between them
 *
 * It must be the container's PID 1 (see `ContainerProcessReaper`): elsewhere the first reset fails and it exits 1.
 * Settings come from options, then the environment: KELPIE_GATE_PORT (8090), KELPIE_GATE_WORK_DIR (/work),
 * KELPIE_GATE_READY_FILE (/tmp/gate-ready), KELPIE_GATE_MAX_BODY_BYTES (512 MiB), KELPIE_GATE_CLEAN_DIRS
 * (colon-separated directories emptied at start and after every run, e.g. /tmp) and KELPIE_GATE_MAX_RUNS (100 runs,
 * then exit 0). See docs/security/gate-pod.md.
 *
 * @see \Kelpie\Tests\Console\Command\GateServeCommandTest PHPUnit test case
 */
final class GateServeCommand extends RunCommand
{
    private const int DEFAULT_PORT = 8090;

    private const int DEFAULT_MAX_BODY_BYTES = 536870912;

    private const int DEFAULT_MAX_RUNS = 100;

    /**
     * Constructor
     *
     * @param Logger     $logger     Logger
     * @param GateServer $gateServer The server
     */
    public function __construct(
        Logger $logger,
        private readonly GateServer $gateServer,
    ) {
        parent::__construct($logger, 'kelpie:gate-serve');
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this->setDescription('Serve gate runs (the gate pod): unpack the checkout, run the gate steps, answer, reset the container')
            ->addOption('listen', null, InputOption::VALUE_REQUIRED, 'Address to listen on', '0.0.0.0')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port (KELPIE_GATE_PORT, default 8090; 0 picks a free one)')
            ->addOption('work-dir', null, InputOption::VALUE_REQUIRED, 'Where runs are unpacked; emptied at start (KELPIE_GATE_WORK_DIR, default /work)')
            ->addOption('ready-file', null, InputOption::VALUE_REQUIRED, 'Written with the address once listening (KELPIE_GATE_READY_FILE, default /tmp/gate-ready)')
            ->addOption('max-body-bytes', null, InputOption::VALUE_REQUIRED, 'Largest archive accepted (KELPIE_GATE_MAX_BODY_BYTES, default 512 MiB)')
            ->addOption('clean', null, InputOption::VALUE_REQUIRED, 'Colon-separated directories emptied at start and after every run (KELPIE_GATE_CLEAN_DIRS)')
            ->addOption('max-runs', null, InputOption::VALUE_REQUIRED, 'Runs to serve before exiting 0 (KELPIE_GATE_MAX_RUNS, default 100)');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(Input $input, Output $output): int
    {
        $port    = $this->setting($input, 'port', 'KELPIE_GATE_PORT', (string) self::DEFAULT_PORT);
        $maxBody = $this->setting($input, 'max-body-bytes', 'KELPIE_GATE_MAX_BODY_BYTES', (string) self::DEFAULT_MAX_BODY_BYTES);
        $maxRuns = $this->setting($input, 'max-runs', 'KELPIE_GATE_MAX_RUNS', (string) self::DEFAULT_MAX_RUNS);
        if (preg_match('/^\d{1,5}$/', $port) !== 1 || (int) $port > 65535 || preg_match('/^[1-9]\d{0,11}$/', $maxBody) !== 1 || preg_match('/^[1-9]\d{0,5}$/', $maxRuns) !== 1) {
            $output->writeln('<error>The port must be 0 to 65535, and the body limit and run limit positive numbers.</error>');

            return self::FAILURE;
        }

        $clean = array_values(array_filter(explode(':', $this->setting($input, 'clean', 'KELPIE_GATE_CLEAN_DIRS', '')), static function (string $directory): bool {
            return $directory !== '';
        }));

        return $this->gateServer->serve(
            'tcp://' . ($this->optionOrNull($input, 'listen') ?? '0.0.0.0') . ':' . $port,
            $this->setting($input, 'work-dir', 'KELPIE_GATE_WORK_DIR', '/work'),
            $this->setting($input, 'ready-file', 'KELPIE_GATE_READY_FILE', '/tmp/gate-ready'),
            (int) $maxBody,
            $clean,
            (int) $maxRuns,
        );
    }

    /**
     * An option, else an environment variable, else the default
     *
     * @param Input  $input    The input
     * @param string $option   The option
     * @param string $variable The environment variable
     * @param string $default  The default
     *
     * @return string
     */
    private function setting(Input $input, string $option, string $variable, string $default): string
    {
        return $this->optionOrNull($input, $option) ?? ((string) getenv($variable) ?: $default);
    }
}
