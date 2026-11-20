<?php

declare(strict_types=1);

namespace Kelpie\Service\Autofix;

use DateTimeImmutable;
use DateTimeZone;
use Kelpie\GitLab\DiscussionWriter;
use Kelpie\HttpClient\BotHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface as Logger;
use Ubix\HttpClient\CurlHttpClient;
use Ubix\Service\JsonService;

/**
 * Tells an MR that Kelpie refused its job because the project is over the daily job cap
 *
 * One plain MR note (not a thread, so nothing to resolve) and the `kelpie::over-cap` label, at most once per MR per
 * 24 hours: a note of ours that carries the marker and is younger than that means it was said already. The bot posts
 * it, and the event filter ignores the bot's own notes, so the notice never queues a job. A GitLab failure is
 * logged and swallowed; it must never break enqueueing.
 *
 * @see \Kelpie\Tests\Service\Autofix\CapNoticeServiceTest PHPUnit test case
 */
final class CapNoticeService
{
    public const string MARKER = '<!-- kelpie:over-cap -->';

    public const string LABEL = 'kelpie::over-cap';

    private const string WINDOW = '-1 day';

    private const string MEMO = '-10 minutes';

    private const int HTTP_TIMEOUT = 4; // Under GitLab's webhook response timeout: a slow GitLab must not make a delivery fail

    private bool $writerResolved = false;

    /**
     * The MRs looked at in this process, so repeated refusals do not each call GitLab
     *
     * @var array<string, DateTimeImmutable>
     */
    private array $looked = [];

    /**
     * Constructor
     *
     * @param Logger            $logger Logger
     * @param ?DiscussionWriter $writer The bot's writer; null builds one on the bot token and API URL of the process
     *                                  environment, and with neither set nothing is posted
     */
    public function __construct(
        private readonly Logger $logger,
        private ?DiscussionWriter $writer = null,
    ) {
        $this->writerResolved = $writer !== null;
    }

    /**
     * Say it on the MR, unless it was said within the last 24 hours
     *
     * @param string             $project The project path
     * @param int                $mrIid   The MR IID
     * @param int                $used    Jobs the project created in the last 24 hours
     * @param int                $limit   The jobs-per-day cap
     * @param ?DateTimeImmutable $freesAt When the cap frees up, when known
     * @param DateTimeImmutable  $now     The current time
     *
     * @return bool Whether a note was posted
     */
    public function jobsPerDayExceeded(string $project, int $mrIid, int $used, int $limit, ?DateTimeImmutable $freesAt, DateTimeImmutable $now): bool
    {
        $writer = $this->writer();
        if ($writer === null || $mrIid < 1) {
            return false;
        }

        $key = $project . '!' . $mrIid;
        if (isset($this->looked[$key]) && $this->looked[$key] > $now->modify(self::MEMO)) {
            return false;
        }
        $this->looked[$key] = $now;

        $recent = $writer->recentNotes($project, $mrIid);
        if ($recent === null) {
            $this->logger->warning('Cap notice skipped: the MR notes could not be read', ['mr_iid' => $mrIid, 'project_path' => $project]);

            return false;
        }

        $since = $now->modify(self::WINDOW);
        foreach ($recent as $note) {
            if (str_contains($note->body, self::MARKER) && $note->createdAt > $since) {
                return false;
            }
        }

        $noteId = $writer->openNote($project, $mrIid, $this->body($used, $limit, $freesAt));
        if ($noteId === null) {
            return false;
        }

        $writer->addLabel($project, $mrIid, self::LABEL);
        $this->logger->info('Cap notice posted', ['mr_iid' => $mrIid, 'project_path' => $project]);

        return true;
    }

    /**
     * The writer, built on first use from the environment when none was given
     *
     * @return ?DiscussionWriter Null when GitLab is not configured
     */
    private function writer(): ?DiscussionWriter
    {
        if ($this->writerResolved) {
            return $this->writer;
        }

        $this->writerResolved = true;
        $apiUrl               = rtrim((string) getenv('GITLAB_API_URL'), '/');
        $token                = (string) getenv('KELPIE_BOT_GITLAB_TOKEN');
        if ($apiUrl === '' || $token === '') {
            return null;
        }

        $factory      = new Psr17Factory();
        $client       = new BotHttpClient($this->logger, new CurlHttpClient($this->logger, $factory, self::HTTP_TIMEOUT), $token);
        $this->writer = new DiscussionWriter($this->logger, $client, $factory, $factory, $apiUrl, new JsonService($this->logger));

        return $this->writer;
    }

    /**
     * @param int                $used    Jobs in the window
     * @param int                $limit   The cap
     * @param ?DateTimeImmutable $freesAt When it frees up
     *
     * @return string
     */
    private function body(int $used, int $limit, ?DateTimeImmutable $freesAt): string
    {
        $when = $freesAt === null ? 'within 24 h' : 'around ' . $freesAt->setTimezone(new DateTimeZone('UTC'))->format('H:i') . ' UTC';

        return sprintf(
            "%s\nKelpie is over its daily job cap for this project (%d of %d in 24 h). Threads here are not triaged until it frees up, %s.",
            self::MARKER,
            $used,
            $limit,
            $when,
        );
    }
}
