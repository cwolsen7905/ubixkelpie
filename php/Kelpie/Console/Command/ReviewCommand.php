<?php

declare(strict_types=1);

namespace Kelpie\Console\Command;

use Kelpie\Service\Review\ReviewService;
use Kelpie\Service\Review\ReviewSettingsService;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as Output;

/**
 * Review the merge request of a GitLab CI pipeline and post one thread per finding plus one summary note
 *
 * Run from the Kelpie image in the MR pipeline of any repo (`ci/kelpie.yml`); it needs a model key and a review
 * token, not a Kelpie server or database. Settings are the `AI_REVIEW_*` variables and the CI merge request
 * variables. Exit: 0 reviewed or skipped, 1 blocking findings in blocking mode, 75 could not run, 2 bad mode.
 *
 * Does not extend the uBixCore command base: that base requires a `Ubix\Console\Command\` class name,
 * so a host command cannot use it. Discovery only needs a Symfony command.
 *
 * @see \Kelpie\Tests\Console\Command\ReviewCommandTest PHPUnit test case
 */
final class ReviewCommand extends SymfonyCommand
{
    /**
     * Constructor
     *
     * @param Logger                $logger                Logger
     * @param ReviewSettingsService $reviewSettingsService Reads the settings
     * @param ReviewService         $reviewService         Reviews the merge request
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ReviewSettingsService $reviewSettingsService,
        private readonly ReviewService $reviewService,
    ) {
        parent::__construct('kelpie:review');
    }

    /**
     * {@inheritDoc}
     */
    protected function configure(): void
    {
        $this->setDescription('Review the merge request of this CI pipeline: threads per finding and one summary note')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Never call GitLab; print the review and every comment it would post')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'The checkout (default: AI_REVIEW_REPO, CI_PROJECT_DIR or the working directory)')
            ->addOption('diff', null, InputOption::VALUE_REQUIRED, 'Review this diff file instead of git\'s diff (no merge request needed)')
            ->addOption('canned', null, InputOption::VALUE_REQUIRED, 'Use this file as the model\'s reply instead of calling the model')
            ->addOption('existing', null, InputOption::VALUE_REQUIRED, 'A JSON file of MR discussions to dedupe against, instead of the API');
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(Input $input, Output $output): int
    {
        $env = [];
        foreach (getenv() as $name => $value) {
            $env[(string) $name] = (string) $value;
        }

        if (!$this->reviewSettingsService->modeIsValid($env)) {
            $output->writeln('<error>AI_REVIEW_MODE must be advisory or blocking, got \'' . ($env['AI_REVIEW_MODE'] ?? '') . '\'</error>');

            return 2;
        }

        $settings = $this->reviewSettingsService->fromEnvironment(
            $env,
            $input->getOption('dry-run') === true,
            $this->option($input, 'repo'),
            $this->option($input, 'diff'),
            $this->option($input, 'canned'),
            $this->option($input, 'existing'),
        );

        $outcome = $this->reviewService->review($settings);
        $this->logger->info('Command finished', ['command' => $this->getName(), 'exit' => $outcome->exitCode]);

        foreach ($outcome->lines as $line) {
            $output->writeln($line, Output::OUTPUT_RAW);
        }

        return $outcome->exitCode;
    }

    /**
     * @param Input  $input The input
     * @param string $name  The option
     *
     * @return string Empty when not given
     */
    private function option(Input $input, string $name): string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : '';
    }
}
