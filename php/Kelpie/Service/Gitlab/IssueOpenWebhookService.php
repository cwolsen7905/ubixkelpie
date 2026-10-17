<?php

declare(strict_types=1);

namespace Kelpie\Service\Gitlab;

use Kelpie\DataTransferObject\IssueIntakeResult;
use Kelpie\Enum\IssueIntake;
use Psr\Log\LoggerInterface as Logger;

/**
 * Intake for an Issue Hook: classify the event and log the class (design sections 2 and 6)
 *
 * - triage: a new issue on an enrolled project, not from the bot or a skipped agent
 * - gate: the gate label was added by a user whose project access, read through the API, is Developer or higher
 * - ignored: everything else, with the reason
 *
 * Nothing is posted to GitLab. The issue's title and description are never read: the issue is
 * untrusted, and only the project, the issue number and the acting user (data) are logged.
 * The class depends on the event alone, so a replayed event gets the same class and nothing is stored.
 *
 * @see \Kelpie\Tests\Service\Gitlab\IssueOpenWebhookServiceTest PHPUnit test case
 */
final class IssueOpenWebhookService
{
    /**
     * GitLab's Developer access level
     */
    public const int DEVELOPER = 30;

    /**
     * Constructor
     *
     * @param Logger                    $logger       Logger
     * @param MemberAccessReaderService $memberAccess Reads the actor's project access level
     * @param bool                      $enabled      KELPIE_AUTOFIX_ENABLED: when false every event is ignored
     * @param string                    $botUsername  The Kelpie bot user
     * @param string[]                  $skipAuthors  Other usernames to ignore (Shepherd agents)
     * @param string[]                  $projects     Enrolled project paths
     * @param string                    $gateLabel    The label a Developer applies to let fixing continue
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly MemberAccessReaderService $memberAccess,
        private readonly bool $enabled = false,
        private readonly string $botUsername = 'kelpie-bot',
        private readonly array $skipAuthors = [],
        private readonly array $projects = ['ubixsys/ubixkelpie'],
        private readonly string $gateLabel = 'kelpie::fix',
    ) {
    }

    /**
     * Classify an Issue Hook payload and log the result
     *
     * @param array<int|string, mixed> $payload The decoded webhook payload
     *
     * @return string A human-readable outcome, "<class>: <reason>"
     */
    public function handle(array $payload): string
    {
        $result = $this->classify($payload);

        $this->logger->info('GitLab webhook: issue intake', [
            'access_level' => $result->accessLevel,
            'actor'        => $result->actor,
            'class'        => $result->class->value,
            'issue_iid'    => $result->issueIid,
            'project_path' => $result->projectPath,
            'reason'       => $result->reason,
        ]);

        return $result->class->value . ': ' . $result->reason;
    }

    /**
     * Classify an Issue Hook payload
     *
     * @param array<int|string, mixed> $payload The decoded webhook payload
     *
     * @return IssueIntakeResult
     */
    public function classify(array $payload): IssueIntakeResult
    {
        $project = $this->arr($payload, 'project');
        $issue   = $this->arr($payload, 'object_attributes');
        $user    = $this->arr($payload, 'user');
        $path    = $this->str($project, 'path_with_namespace');
        $iid     = is_int($issue['iid'] ?? null) ? $issue['iid'] : 0;
        $actor   = $this->str($user, 'username');
        $action  = $this->str($issue, 'action');

        if (!$this->enabled) {
            return $this->ignored('autofix disabled', $path, $iid, $actor);
        }
        if (!in_array($path, $this->projects, true)) {
            return $this->ignored('project not enrolled', $path, $iid, $actor);
        }
        if ($iid === 0) {
            return $this->ignored('no issue iid', $path, $iid, $actor);
        }
        if ($actor === $this->botUsername || in_array($actor, $this->skipAuthors, true)) {
            return $this->ignored('actor is the bot or a Shepherd agent', $path, $iid, $actor);
        }
        if ($action === 'open') {
            return new IssueIntakeResult(IssueIntake::Triage, 'new issue on an enrolled project', $path, $iid, $actor);
        }
        if ($this->labelAdded($payload)) {
            return $this->gate($payload, $project, $user, $path, $iid, $actor);
        }

        return $this->ignored('not an open or gate-label event', $path, $iid, $actor);
    }

    /**
     * Verify the actor through the API; anything but Developer or higher is ignored
     *
     * @param array<int|string, mixed> $payload The payload
     * @param array<int|string, mixed> $project The project object
     * @param array<int|string, mixed> $user    The acting user
     * @param string                   $path    The project path
     * @param int                      $iid     The issue iid
     * @param string                   $actor   The acting username
     *
     * @return IssueIntakeResult
     */
    private function gate(array $payload, array $project, array $user, string $path, int $iid, string $actor): IssueIntakeResult
    {
        $projectId = is_int($project['id'] ?? null) ? $project['id'] : (is_int($this->arr($payload, 'object_attributes')['project_id'] ?? null) ? $this->arr($payload, 'object_attributes')['project_id'] : 0);
        $userId    = is_int($user['id'] ?? null) ? $user['id'] : 0;

        if ($projectId === 0 || $userId === 0) {
            return $this->ignored('gate label without a project or user id', $path, $iid, $actor);
        }

        $level = $this->memberAccess->level($projectId, $userId);
        if ($level === null) {
            return $this->ignored('gate actor access could not be verified', $path, $iid, $actor);
        }
        if ($level < self::DEVELOPER) {
            return $this->ignored('gate actor below Developer', $path, $iid, $actor, $level);
        }

        return new IssueIntakeResult(IssueIntake::Gate, 'gate label added by a Developer or higher', $path, $iid, $actor, $level);
    }

    /**
     * Whether the gate label is in the event's current labels but not its previous ones
     *
     * @param array<int|string, mixed> $payload The payload
     *
     * @return bool
     */
    private function labelAdded(array $payload): bool
    {
        $labels = $this->arr($this->arr($payload, 'changes'), 'labels');

        return $this->hasLabel($labels['current'] ?? null) && !$this->hasLabel($labels['previous'] ?? null);
    }

    /**
     * @param mixed $labels A label list from the event
     *
     * @return bool Whether the gate label is in it
     */
    private function hasLabel(mixed $labels): bool
    {
        foreach (is_array($labels) ? $labels : [] as $label) {
            if (is_array($label) && ($label['title'] ?? null) === $this->gateLabel) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $reason      Why
     * @param string $path        The project path
     * @param int    $iid         The issue iid
     * @param string $actor       The acting username
     * @param ?int   $accessLevel The verified access level, when one was read
     *
     * @return IssueIntakeResult
     */
    private function ignored(string $reason, string $path, int $iid, string $actor, ?int $accessLevel = null): IssueIntakeResult
    {
        return new IssueIntakeResult(IssueIntake::Ignored, $reason, $path, $iid, $actor, $accessLevel);
    }

    /**
     * @param array<int|string, mixed> $data The array
     * @param string                   $key  The key
     *
     * @return array<int|string, mixed>
     */
    private function arr(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * @param array<int|string, mixed> $data The array
     * @param string                   $key  The key
     *
     * @return string
     */
    private function str(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }
}
