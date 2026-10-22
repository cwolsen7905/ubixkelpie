<?php

declare(strict_types=1);

namespace Kelpie\Model;

use DateTimeInterface;
use Ubix\Model\AbstractModel as Model;

/**
 * A persisted autofix job: one MR head (and thread) that has something needing resolution
 *
 * @see \Kelpie\Tests\Model\AutofixJobRecordTest PHPUnit test case
 */
final class AutofixJobRecord extends Model
{
    /**
     * Constructor
     *
     * @param ?string            $projectPath  The projectPath
     * @param ?int               $mrIid        The mrIid
     * @param ?string            $headSha      The headSha
     * @param ?string            $source       The source
     * @param ?int               $id           The id
     * @param ?string            $discussionId The discussionId
     * @param ?string            $threadKey    The threadKey
     * @param ?bool              $afterAutofix The afterAutofix
     * @param ?string            $status       The status
     * @param ?string            $triageClass  The triageClass
     * @param ?int               $attempt      The attempt
     * @param ?string            $commitSha    The commitSha
     * @param ?DateTimeInterface $dateCreated  The dateCreated
     * @param ?string            $steering     The steering
     */
    public function __construct(
        private ?string $projectPath = null,
        private ?int $mrIid = null,
        private ?string $headSha = null,
        private ?string $source = null,
        private ?int $id = null,
        private ?string $discussionId = null,
        private ?string $threadKey = null,
        private ?bool $afterAutofix = null,
        private ?string $status = null,
        private ?string $triageClass = null,
        private ?int $attempt = null,
        private ?string $commitSha = null,
        private ?DateTimeInterface $dateCreated = null,
        private ?string $steering = null,
    ) {
    }

    /**
     * Get the value of id
     *
     * @return ?int The value of id
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Get the value of projectPath
     *
     * @return ?string The value of projectPath
     */
    public function getProjectPath(): ?string
    {
        return $this->projectPath;
    }

    /**
     * Get the value of mrIid
     *
     * @return ?int The value of mrIid
     */
    public function getMrIid(): ?int
    {
        return $this->mrIid;
    }

    /**
     * Get the value of headSha
     *
     * @return ?string The value of headSha
     */
    public function getHeadSha(): ?string
    {
        return $this->headSha;
    }

    /**
     * Get the value of discussionId
     *
     * @return ?string The value of discussionId
     */
    public function getDiscussionId(): ?string
    {
        return $this->discussionId;
    }

    /**
     * Get the value of threadKey
     *
     * @return ?string The value of threadKey
     */
    public function getThreadKey(): ?string
    {
        return $this->threadKey;
    }

    /**
     * Get the value of source
     *
     * @return ?string The value of source
     */
    public function getSource(): ?string
    {
        return $this->source;
    }

    /**
     * Get the value of status
     *
     * @return ?string The value of status
     */
    public function getStatus(): ?string
    {
        return $this->status;
    }

    /**
     * Get the value of triageClass
     *
     * @return ?string The value of triageClass
     */
    public function getTriageClass(): ?string
    {
        return $this->triageClass;
    }

    /**
     * Get the value of attempt
     *
     * @return ?int The value of attempt
     */
    public function getAttempt(): ?int
    {
        return $this->attempt;
    }

    /**
     * Get the value of commitSha
     *
     * @return ?string The value of commitSha
     */
    public function getCommitSha(): ?string
    {
        return $this->commitSha;
    }

    /**
     * Get the value of steering
     *
     * @return ?string The value of steering
     */
    public function getSteering(): ?string
    {
        return $this->steering;
    }

    /**
     * Set the value of steering
     *
     * @param ?string $steering The value of steering
     *
     * @return void
     */
    public function setSteering(?string $steering): void
    {
        $this->steering = $steering;
    }

    /**
     * Get the value of dateCreated
     *
     * @return ?DateTimeInterface The value of dateCreated
     */
    public function getDateCreated(): ?DateTimeInterface
    {
        return $this->dateCreated;
    }

    /**
     * Set the value of id
     *
     * @param ?int $id The value of id
     *
     * @return void
     */
    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    /**
     * Whether the job still has work to do (pending or running)
     *
     * @return bool True while the job is open
     */
    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'running'], true);
    }

    /**
     * Set the value of projectPath
     *
     * @param ?string $projectPath The value for projectPath
     *
     * @return void
     */
    public function setProjectPath(?string $projectPath): void
    {
        $this->projectPath = $projectPath;
    }

    /**
     * Set the value of mrIid
     *
     * @param ?int $mrIid The value for mrIid
     *
     * @return void
     */
    public function setMrIid(?int $mrIid): void
    {
        $this->mrIid = $mrIid;
    }

    /**
     * Set the value of headSha
     *
     * @param ?string $headSha The value for headSha
     *
     * @return void
     */
    public function setHeadSha(?string $headSha): void
    {
        $this->headSha = $headSha;
    }

    /**
     * Set the value of source
     *
     * @param ?string $source The value for source
     *
     * @return void
     */
    public function setSource(?string $source): void
    {
        $this->source = $source;
    }

    /**
     * Set the value of discussionId
     *
     * @param ?string $discussionId The value for discussionId
     *
     * @return void
     */
    public function setDiscussionId(?string $discussionId): void
    {
        $this->discussionId = $discussionId;
    }

    /**
     * Set the value of threadKey
     *
     * @param ?string $threadKey The value for threadKey
     *
     * @return void
     */
    public function setThreadKey(?string $threadKey): void
    {
        $this->threadKey = $threadKey;
    }

    /**
     * Get the value of afterAutofix
     *
     * @return ?bool The value of afterAutofix
     */
    public function getAfterAutofix(): ?bool
    {
        return $this->afterAutofix;
    }

    /**
     * Set the value of afterAutofix
     *
     * @param ?bool $afterAutofix The value for afterAutofix
     *
     * @return void
     */
    public function setAfterAutofix(?bool $afterAutofix): void
    {
        $this->afterAutofix = $afterAutofix;
    }

    /**
     * Set the value of status
     *
     * @param ?string $status The value for status
     *
     * @return void
     */
    public function setStatus(?string $status): void
    {
        $this->status = $status;
    }

    /**
     * Set the value of triageClass
     *
     * @param ?string $triageClass The value for triageClass
     *
     * @return void
     */
    public function setTriageClass(?string $triageClass): void
    {
        $this->triageClass = $triageClass;
    }

    /**
     * Set the value of attempt
     *
     * @param ?int $attempt The value for attempt
     *
     * @return void
     */
    public function setAttempt(?int $attempt): void
    {
        $this->attempt = $attempt;
    }

    /**
     * Set the value of commitSha
     *
     * @param ?string $commitSha The value for commitSha
     *
     * @return void
     */
    public function setCommitSha(?string $commitSha): void
    {
        $this->commitSha = $commitSha;
    }

    /**
     * Set the value of dateCreated
     *
     * @param ?DateTimeInterface $dateCreated The value for dateCreated
     *
     * @return void
     */
    public function setDateCreated(?DateTimeInterface $dateCreated): void
    {
        $this->dateCreated = $dateCreated;
    }
}
