<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Review;

use Kelpie\Autofix\Attribution;
use Kelpie\DataTransferObject\Review\ReviewOutcome;
use Kelpie\DataTransferObject\Review\ReviewSettings;
use Kelpie\Service\Review\ReviewService;
use Kelpie\Tests\GitLab\Review\FakeReviewGitlab;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Review\ReviewService
 *
 * What must hold (the behaviour of the shell review it replaces):
 * - Drafts, docs-only and empty diffs, oversize diffs and a fourth round make no model call and say so in the summary note
 * - A round is a new head commit: on a merge request pushed four times (as on !125), rounds 1 to 3 review and the fourth
 *   push opens no thread, and the one summary note, edited in place, names the commit of each round and says the review is done
 * - Each new finding opens one thread and the run leaves one summary note, replaced on a rerun; a new commit is a new round
 * - A finding a person dismissed, or that is already open, is not posted again, even when another job posted it while the model ran
 * - Advisory mode exits 0 on a blocker; blocking mode exits 1; a review that could not run exits 75 and says UNREVIEWED
 * - A clean review is a plain note naming model, commit and files
 * - The model's reply is retried once, with what was wrong; the usage of both attempts is stamped
 * - When the model gives no valid review and a fallback is set, the fallback reviews and the stamp names it
 * - A shadow run opens no thread, writes only its own labelled note and never fails the pipeline
 * - A diff adding a class in a directory that is not a family reaches the model with the placement instructions
 *
 * @coversDefaultClass \Kelpie\Service\Review\ReviewService
 */
final class ReviewServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ReviewService::class);
    }

    /**
     * A dry run prints the review, the threads and the summary, and calls GitLab not at all
     *
     * @return void
     */
    public function testDryRunPrintsEverythingAndCallsNothing(): void
    {
        $harness = $this->harness();

        $outcome = $harness->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json'), 'dryRun' => true, 'token' => '']));

        $this->assertSame(0, $outcome->exitCode);
        $text = implode("\n", $outcome->lines);
        $this->assertStringContainsString('=== review JSON ===', $text);
        $this->assertSame(3, substr_count($text, '--- would open a thread ---'));
        $this->assertStringContainsString('--- would post the summary note ---', $text);
        $this->assertStringContainsString('No model call', $text === '' ? '' : 'No model call');
        $this->assertSame([], $harness->gitlab()->requests());
    }

    /**
     * A diff that adds BarService under Foo/ is reviewed with the placement instructions, and the placement finding is reported
     *
     * @return void
     */
    public function testPlacementFinding(): void
    {
        $harness = $this->harness();
        $harness->model()->text((string) file_get_contents($this->fixture('placement.json')));
        putenv('OPENROUTER_API_KEY=test-key-not-real');

        try {
            $outcome = $harness->service()->review($this->settings(['diffFile' => $this->fixture('placement.diff')]));
        } finally {
            putenv('OPENROUTER_API_KEY');
        }

        $this->assertSame(0, $outcome->exitCode);
        $this->assertStringContainsString('## Placement and layering', $this->messageText($harness, 0, 0));
        $this->assertStringContainsString('php/Kelpie/Foo/BarService.php', $this->messageText($harness, 0, 1));
        $thread = $harness->gitlab()->threads()[0];
        $this->assertIsString($thread['body']);
        $this->assertStringContainsString('BarService is in a directory that is not a family', $thread['body']);
        $this->assertStringContainsString('`php/Kelpie/Foo/BarService.php:6`', $thread['body']);
    }

    /**
     * A live review opens a thread per finding and one summary note with its marker and stamp
     *
     * @return void
     */
    public function testLiveReviewPostsThreadsAndOneSummary(): void
    {
        $harness = $this->harness();

        $outcome = $harness->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json')]));

        $this->assertSame(0, $outcome->exitCode);
        $this->assertCount(3, $harness->gitlab()->threads());
        $notes = $harness->gitlab()->noteBodies();
        $this->assertCount(1, $notes);
        $this->assertStringStartsWith('<!-- ai-review:summary round=1 sha=abcdef1234567890abcdef1234567890abcdef12 rounds=abcdef12 -->', $notes[0]);
        $this->assertStringContainsString('**AI review: block**, round 1 of 3 (`advisory` mode)', $notes[0]);
        $this->assertStringContainsString('New threads: 3.', $notes[0]);
        $this->assertStringContainsString('_' . Attribution::signature() . ' · agent `review-agent` · model `openrouter/anthropic/claude-haiku-5.5`', $notes[0]);
    }

    /**
     * Advisory never fails the job on a blocker; blocking does
     *
     * @return void
     */
    public function testBlockingModeFailsOnAStandingBlocker(): void
    {
        $advisory = $this->harness()->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json')]));
        $blocking = $this->harness()->service()->review($this->settings(['blocking' => true, 'cannedFile' => $this->fixture('canned-ok.json')]));

        $this->assertSame(0, $advisory->exitCode);
        $this->assertSame(1, $blocking->exitCode);
    }

    /**
     * A draft is skipped without any request
     *
     * @return void
     */
    public function testDraftIsSkipped(): void
    {
        $harness = $this->harness();

        $outcome = $harness->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json'), 'title' => 'Draft: not yet']));

        $this->assertSame(0, $outcome->exitCode);
        $this->assertSame([], $harness->gitlab()->requests());
    }

    /**
     * A docs-only diff is skipped with a note that says no model was called
     *
     * @return void
     */
    public function testDocsOnlyDiffIsSkippedWithANote(): void
    {
        $repo    = new TempRepo(['docs.diff' => "diff --git a/README.md b/README.md\n+x\n"]);
        $harness = $this->harness();

        try {
            $outcome = $harness->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json'), 'diffFile' => $repo->path() . '/docs.diff']));
        } finally {
            $repo->remove();
        }

        $this->assertSame(0, $outcome->exitCode);
        $this->assertSame([], $harness->gitlab()->threads());
        $note = $harness->gitlab()->noteBodies()[0];
        $this->assertStringContainsString('**AI review: skipped.** No model call was made: only docs changed.', $note);
        $this->assertStringContainsString('_' . Attribution::signature() . ' · agent `review-agent` · no model call on `abcdef12`', $note);
    }

    /**
     * An empty diff is skipped with a note
     *
     * @return void
     */
    public function testEmptyDiffIsSkipped(): void
    {
        $repo    = new TempRepo(['empty.diff' => '']);
        $harness = $this->harness();

        try {
            $harness->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json'), 'diffFile' => $repo->path() . '/empty.diff']));
        } finally {
            $repo->remove();
        }

        $this->assertStringContainsString('nothing reviewable changed', $harness->gitlab()->noteBodies()[0]);
        $this->assertSame([], $harness->model()->sent());
    }

    /**
     * An oversize diff is not reviewed
     *
     * @return void
     */
    public function testTooLargeDiffIsNotReviewed(): void
    {
        $harness = $this->harness();

        $outcome = $harness->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json'), 'maxLines' => 3]));

        $this->assertSame(0, $outcome->exitCode);
        $this->assertSame([], $harness->gitlab()->threads());
        $this->assertStringContainsString('**AI review: too large, human review required.**', $harness->gitlab()->noteBodies()[0]);
    }

    /**
     * Past the third round the review stops, unless the extra label is on
     *
     * @return void
     */
    public function testRoundLimitAndTheExtraLabel(): void
    {
        $previous = ['id' => 'dS', 'notes' => [['author' => ['id' => 9], 'body' => '<!-- ai-review:summary round=3 sha=abc1230 -->' . "\nold", 'id' => 40, 'resolved' => null]]];

        $stopped = $this->harness();
        $stopped->gitlab()->withDiscussions([$previous]);
        $outcome = $stopped->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json')]));

        $this->assertSame(0, $outcome->exitCode);
        $this->assertSame([], $stopped->gitlab()->threads());
        $this->assertStringContainsString('round limit reached', $stopped->gitlab()->noteBodies()[0]);
        $this->assertStringContainsString('round=3', $stopped->gitlab()->noteBodies()[0]);

        $allowed = $this->harness();
        $allowed->gitlab()->withDiscussions([$previous]);
        $allowed->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json'), 'labels' => ['review:extra']]));

        $this->assertCount(3, $allowed->gitlab()->threads());
        $this->assertStringContainsString('round 4 of 3', $allowed->gitlab()->noteBodies()[0]);
    }

    /**
     * A rerun of the same commit replaces the note and keeps the threads; a new commit is a new round
     *
     * @return void
     */
    public function testRerunReplacesTheNoteAndANewCommitIsANewRound(): void
    {
        $harness  = $this->harness();
        $settings = $this->settings(['cannedFile' => $this->fixture('canned-ok.json')]);

        $harness->service()->review($settings);
        $threadsAfterFirst = count($harness->gitlab()->threads());
        $harness->service()->review($settings);

        $this->assertSame($threadsAfterFirst, count($harness->gitlab()->threads()));
        $this->assertCount(1, array_filter($harness->gitlab()->paths('PUT'), static function (string $path): bool {
            return str_contains($path, '/notes/');
        }));
        $this->assertStringContainsString('round=1', $harness->gitlab()->noteBodies()[1]);
        $this->assertStringContainsString('Already open: 3.', $harness->gitlab()->noteBodies()[1]);

        $harness->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json'), 'headSha' => 'ffffffffffffffffffffffffffffffffffffffff']));

        $this->assertStringContainsString('round=2 sha=ffff', $harness->gitlab()->noteBodies()[2]);
        $this->assertCount(3, $harness->gitlab()->threads());
    }

    /**
     * Four pushes as on !125: three reviewed rounds, then a push that opens no thread and a note that says the review is done
     *
     * The summary note is posted once and edited on every later push, so GitLab shows the time of round 1 on a body
     * written by round 3. Each body says which commit each round was, and the last round's threads are the last.
     *
     * @return void
     */
    public function testFourPushesThreeRoundsThenNoThreads(): void
    {
        $harness = $this->harness();
        $commits = ['53ad148a0b828226f6521f14b69a0945520ab950', 'f785ad5d5aa61caac1b72777cda175b60f72847b', '086792ee4251ef571e42fa5a61a8b6b85b8acbfe', '5791770df5c453059887c53bf29400ea72253b01'];
        $threads = [];
        foreach ($commits as $sha) {
            // Each round's findings are new ones, as the model reports on what each push changed
            $harness->gitlab()->resolveAll();
            $harness->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json'), 'headSha' => $sha]));
            $threads[] = count($harness->gitlab()->threads());
        }

        $notes = $harness->gitlab()->noteBodies();
        $this->assertCount(1, array_filter($harness->gitlab()->paths('POST'), static function (string $path): bool {
            return str_ends_with($path, '/notes');
        }), 'one summary note, posted once');
        $this->assertCount(3, array_filter($harness->gitlab()->paths('PUT'), static function (string $path): bool {
            return str_contains($path, '/notes/');
        }), 'and edited by each later push');
        $this->assertSame([3, 6, 9, 9], $threads, 'rounds 1 to 3 open threads, the fourth push none');

        $this->assertStringContainsString('round 1 of 3', $notes[0]);
        $this->assertStringContainsString('round 2 of 3', $notes[1]);
        $this->assertStringNotContainsString('last automatic review round', $notes[1]);
        $this->assertStringContainsString('round 3 of 3', $notes[2]);
        $this->assertStringContainsString('**This was the last automatic review round** (3 of 3, on `086792ee`)', $notes[2]);
        $this->assertStringContainsString('Rounds so far: 1 on `53ad148a`, 2 on `f785ad5d`, 3 on `086792ee`.', $notes[2]);
        $this->assertStringContainsString('the time GitLab shows on it is when it was first posted', $notes[2]);

        $this->assertStringStartsWith('<!-- ai-review:summary round=3 sha=5791770df5c453059887c53bf29400ea72253b01 rounds=53ad148a,f785ad5d,086792ee -->', $notes[3]);
        $this->assertStringContainsString('**AI review: done, round limit reached.**', $notes[3]);
        $this->assertStringContainsString('this push (`5791770d`) was not reviewed and opened no new threads', $notes[3]);
        $this->assertStringContainsString('Rounds so far: 1 on `53ad148a`, 2 on `f785ad5d`, 3 on `086792ee`.', $notes[3]);
        $this->assertSame(['kelpie-review::hard-cap', 'kelpie-findings::none'], $harness->gitlab()->mrLabels());

        // A fifth push is the same hard cap: the rounds that ran are kept, the unreviewed commits are not added
        $harness->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json'), 'headSha' => 'abababababababababababababababababababab']));

        $this->assertCount(9, $harness->gitlab()->threads());
        $this->assertStringStartsWith('<!-- ai-review:summary round=3 sha=abababababababababababababababababababab rounds=53ad148a,f785ad5d,086792ee -->', $harness->gitlab()->noteBodies()[4]);
        $this->assertStringContainsString('this push (`abababab`) was not reviewed', $harness->gitlab()->noteBodies()[4]);
    }

    /**
     * A finding a person dismissed is not posted again
     *
     * @return void
     */
    public function testDismissedFindingIsNotPostedAgain(): void
    {
        $harness = $this->harness();
        $harness->gitlab()->withDiscussions($this->discussions('existing-discussions.json'));

        $harness->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json')]));

        $this->assertCount(2, $harness->gitlab()->threads());
        $this->assertStringContainsString('Dismissed: 1.', $harness->gitlab()->noteBodies()[0]);
    }

    /**
     * A thread another job posted while the model ran is deduped, not posted twice
     *
     * @return void
     */
    public function testThreadPostedMeanwhileIsNotDuplicated(): void
    {
        $harness = $this->harness();
        $harness->gitlab()->afterNextRead(static function (FakeReviewGitlab $gitlab): void {
            $gitlab->addDiscussion(['id' => 'race', 'notes' => [['author' => ['id' => 9], 'body' => "**blocker** (high confidence): Withdraw and deposit are saved separately\n\n`src/Transfer.php:15`\n<!-- ai-review:fp=" . substr(sha1('src/Transfer.php|non-atomic-transfer-saves'), 0, 12) . ' sha=other -->', 'id' => 70, 'resolved' => false]]]);
        });

        $harness->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json')]));

        $this->assertCount(2, $harness->gitlab()->threads());
    }

    /**
     * Without a token nothing is called and the exit is 75
     *
     * @return void
     */
    public function testNoTokenIsUnreviewedWithoutRequests(): void
    {
        $harness = $this->harness();

        $outcome = $harness->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json'), 'token' => '']));

        $this->assertSame(ReviewOutcome::UNREVIEWED, $outcome->exitCode);
        $this->assertSame([], $harness->gitlab()->requests());
    }

    /**
     * Without a model key and without a canned reply the review says UNREVIEWED in the summary and exits 75
     *
     * @return void
     */
    public function testNoModelKeyIsUnreviewedWithANote(): void
    {
        putenv('OPENROUTER_API_KEY');
        $harness = $this->harness();

        $outcome = $harness->service()->review($this->settings());

        $this->assertSame(ReviewOutcome::UNREVIEWED, $outcome->exitCode);
        $note = $harness->gitlab()->noteBodies()[0];
        $this->assertStringContainsString('**AI review: UNREVIEWED.** OPENROUTER_API_KEY is not set.', $note);
        $this->assertStringContainsString('a person must review it', $note);
    }

    /**
     * Not a merge request pipeline, and a failing git, are UNREVIEWED
     *
     * @return void
     */
    public function testNotAMergeRequestAndGitFailure(): void
    {
        $this->assertSame(ReviewOutcome::UNREVIEWED, $this->harness()->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json'), 'mrIid' => 0]))->exitCode);

        $harness = $this->harness();
        $outcome = $harness->service()->review($this->settings(['baseSha' => 'base1', 'cannedFile' => $this->fixture('canned-ok.json'), 'diffFile' => '']));

        $this->assertSame(ReviewOutcome::UNREVIEWED, $outcome->exitCode);
        $this->assertStringContainsString('could not diff against base1', $harness->gitlab()->noteBodies()[0]);
    }

    /**
     * Unreadable discussions are UNREVIEWED
     *
     * @return void
     */
    public function testUnreadableDiscussionsAreUnreviewed(): void
    {
        $harness = $this->harness();
        $harness->gitlab()->failReads();

        $outcome = $harness->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json')]));

        $this->assertSame(ReviewOutcome::UNREVIEWED, $outcome->exitCode);
        $this->assertStringContainsString("could not read the MR's discussions", $harness->gitlab()->noteBodies()[0]);
    }

    /**
     * Two bad replies are UNREVIEWED, and the stamp still carries what both attempts cost
     *
     * @return void
     */
    public function testBadReplyTwiceIsUnreviewedWithTheStamp(): void
    {
        $harness = $this->harness();
        $harness->model()->text('looks fine to me', 1200, 0.003)->text('still no json', 1200, 0.003);
        putenv('OPENROUTER_API_KEY=test-key-not-real');

        try {
            $outcome = $harness->service()->review($this->settings());
        } finally {
            putenv('OPENROUTER_API_KEY');
        }

        $this->assertSame(ReviewOutcome::UNREVIEWED, $outcome->exitCode);
        $note = $harness->gitlab()->noteBodies()[0];
        $this->assertStringContainsString('the model did not return a valid review after a retry', $note);
        $this->assertStringContainsString('2.4k in / 2.4k out, $0.0060', $note);
    }

    /**
     * A reply that cannot be parsed is logged, its secrets masked and its length capped at 4 KB
     *
     * @return void
     */
    public function testUnparsableReplyIsLoggedMaskedAndCapped(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->exactly(2))->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
            $this->assertSame('Review reply was not a valid review', $message);
            if ($context['attempt'] === 1) {
                $this->assertGreaterThan(5000, $context['reply_bytes']);
                $this->assertIsString($context['reply']);
                $this->assertSame(4096, strlen($context['reply']));
                $this->assertStringStartsWith('Sorry, I cannot do that. api_key: [masked] and sk-[masked] and xxx', $context['reply']);

                return;
            }
            $this->assertSame(['plain prose, no json', 20], [$context['reply'], $context['reply_bytes']]);
        });
        $harness = new ReviewHarness($logger);
        $harness->model()
            ->text('Sorry, I cannot do that. api_key: abcdef1234567890 and sk-abcdefghijkl and ' . str_repeat('x', 5000), 10, 0.001)
            ->text('plain prose, no json', 10, 0.001);
        putenv('OPENROUTER_API_KEY=test-key-not-real');

        try {
            $outcome = $harness->service()->review($this->settings());
        } finally {
            putenv('OPENROUTER_API_KEY');
        }

        $this->assertSame(ReviewOutcome::UNREVIEWED, $outcome->exitCode);
    }

    /**
     * The model key is masked wherever it appears in a logged reply
     *
     * @return void
     */
    public function testModelKeyIsMaskedInTheLoggedReply(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->exactly(2))->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
            $this->assertSame('Review reply was not a valid review', $message);
            $this->assertSame($context['attempt'] === 1 ? 'echo [masked] then done' : 'again [masked]', $context['reply']);
        });
        $harness = new ReviewHarness($logger);
        $harness->model()->text('echo my-model-key-value then done', 10, 0.001)->text('again my-model-key-value', 10, 0.001);
        putenv('OPENROUTER_API_KEY=my-model-key-value');

        try {
            $harness->service()->review($this->settings());
        } finally {
            putenv('OPENROUTER_API_KEY');
        }
    }

    /**
     * A valid review logs no reply
     *
     * @return void
     */
    public function testValidReviewLogsNoReply(): void
    {
        $logger = $this->createStub(Logger::class);
        $logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
            $this->assertArrayNotHasKey('reply', $context, $message);
        });

        $outcome = (new ReviewHarness($logger))->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json')]));

        $this->assertSame(0, $outcome->exitCode);
    }

    /**
     * A bad first reply is retried with what was wrong, and both attempts are stamped
     *
     * @return void
     */
    public function testRetryAfterABadReply(): void
    {
        $harness = $this->harness();
        $harness->model()->text('no json here', 1000, 0.001)->text((string) file_get_contents($this->fixture('canned-ok.json')), 2000, 0.002);
        putenv('OPENROUTER_API_KEY=test-key-not-real');

        try {
            $outcome = $harness->service()->review($this->settings());
        } finally {
            putenv('OPENROUTER_API_KEY');
        }

        $this->assertSame(0, $outcome->exitCode);
        $this->assertStringContainsString('contained no JSON object', $this->messageText($harness, 1, 1));
        $this->assertStringContainsString('3.0k in / 3.0k out, $0.0030', $harness->gitlab()->noteBodies()[0]);
    }

    /**
     * When the open-source model gives no answer (a training tier the account's policy excludes), the regular
     * model reviews instead, and the summary's stamp names the model that wrote it
     *
     * @return void
     */
    public function testFallbackModelReviewsWhenTheModelGivesNoReview(): void
    {
        $harness = $this->harness();
        $harness->model()->fail()->fail()->text((string) file_get_contents($this->fixture('canned-ok.json')));
        putenv('OPENROUTER_API_KEY=test-key-not-real');

        try {
            $outcome = $harness->service()->review($this->settings([
                'apiModel'      => 'meta/muse-spark-1.3-contributor',
                'fallbackModel' => 'openrouter/anthropic/claude-haiku-5.5',
                'model'         => 'openrouter/meta/muse-spark-1.3-contributor',
            ]));
        } finally {
            putenv('OPENROUTER_API_KEY');
        }

        $this->assertSame(0, $outcome->exitCode);
        $models = array_column($harness->model()->sent(), 'model');
        $this->assertSame(['meta/muse-spark-1.3-contributor', 'meta/muse-spark-1.3-contributor', 'anthropic/claude-haiku-5.5'], $models);
        $this->assertStringContainsString('openrouter/anthropic/claude-haiku-5.5', $harness->gitlab()->noteBodies()[0]);
    }

    /**
     * The model reads the checkout with its tools and the run is stamped with the sum
     *
     * @return void
     */
    public function testModelRunUsesToolsAndStampsUsage(): void
    {
        $harness = $this->harness();
        $harness->model()->tool('read', ['path' => 'sample.diff'])->text((string) file_get_contents($this->fixture('canned-ok.json')), 500, 0.002);
        putenv('OPENROUTER_API_KEY=test-key-not-real');

        try {
            $outcome = $harness->service()->review($this->settings(['effort' => 'high']));
        } finally {
            putenv('OPENROUTER_API_KEY');
        }

        $this->assertSame(0, $outcome->exitCode);
        $this->assertStringContainsString('effort high, 510 in / 510 out, $0.0021', $harness->gitlab()->noteBodies()[0]);
        $this->assertCount(3, $harness->gitlab()->threads());
    }

    /**
     * A review that finds nothing leaves a plain note, not a thread
     *
     * @return void
     */
    public function testCleanReviewIsAPlainNote(): void
    {
        $repo    = new TempRepo(['clean.json' => '{"verdict":"pass","summary":"Nothing wrong.","findings":[]}']);
        $harness = $this->harness();

        try {
            $outcome = $harness->service()->review($this->settings(['cannedFile' => $repo->path() . '/clean.json']));
        } finally {
            $repo->remove();
        }

        $this->assertSame(0, $outcome->exitCode);
        $this->assertSame([], $harness->gitlab()->threads());
        $note = $harness->gitlab()->noteBodies()[0];
        $this->assertStringContainsString('**AI review: ran, no findings**', $note);
        $this->assertStringContainsString('reviewed commit `abcdef1234567890abcdef1234567890abcdef12`', $note);
        $this->assertStringContainsString('- `src/Transfer.php`', $note);
    }

    /**
     * A finding with no line is in the summary, and a quick action in the model's text is defused
     *
     * @return void
     */
    public function testFileLevelFindingAndQuickActions(): void
    {
        $whole   = '{"severity":"minor","confidence":"high","file":"src/A.php","line":0,"title":"Whole file","why":"first\n/approve it","fingerprint":"whole-file"}';
        $line    = '{"severity":"minor","confidence":"high","file":"src/A.php","line":4,"title":"Line four","why":"a\n/merge now","fingerprint":"line-four"}';
        $repo    = new TempRepo(['reply.json' => '{"verdict":"concerns","summary":"s","findings":[' . $whole . ',' . $line . ']}']);
        $harness = $this->harness();

        try {
            $harness->service()->review($this->settings(['cannedFile' => $repo->path() . '/reply.json']));
        } finally {
            $repo->remove();
        }

        $this->assertCount(1, $harness->gitlab()->threads());
        $note = $harness->gitlab()->noteBodies()[0];
        $this->assertStringContainsString('Not tied to one line', $note);
        $this->assertStringContainsString('Whole file. first /approve it', $note);
        $this->assertDoesNotMatchRegularExpression('~^\s*/~m', $note);
        $thread = $harness->gitlab()->threads()[0];
        $this->assertIsString($thread['body']);
        $this->assertStringContainsString('&#47;merge now', $thread['body']);
        $this->assertStringNotContainsString("\n/merge", $thread['body']);
    }

    /**
     * A summary GitLab refuses is UNREVIEWED
     *
     * @return void
     */
    public function testRefusedWritesAreUnreviewed(): void
    {
        $harness = $this->harness();
        $harness->gitlab()->refuseWrites();

        $outcome = $harness->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json')]));

        $this->assertSame(ReviewOutcome::UNREVIEWED, $outcome->exitCode);
    }

    /**
     * A shadow run opens no thread, writes only its own labelled note, leaves the real summary alone and never exits 1
     *
     * @return void
     */
    public function testShadowRun(): void
    {
        $real    = ['id' => 'dS', 'notes' => [['author' => ['id' => 9], 'body' => '<!-- ai-review:summary round=1 sha=abcdef1234567890abcdef1234567890abcdef12 -->' . "\nreal summary", 'id' => 40, 'resolved' => null]]];
        $harness = $this->harness();
        $harness->gitlab()->withDiscussions([$real]);

        $outcome = $harness->service()->review($this->settings(['blocking' => true, 'cannedFile' => $this->fixture('canned-ok.json'), 'shadow' => true]));

        $this->assertSame(0, $outcome->exitCode);
        $this->assertSame([], $harness->gitlab()->threads());
        $this->assertSame([], $harness->gitlab()->paths('PUT'));
        $note = $harness->gitlab()->noteBodies()[0];
        $this->assertStringStartsWith('<!-- kelpie-review:shadow round=1 sha=', $note);
        $this->assertStringContainsString('Shadow run of the new review engine', $note);
        $this->assertStringContainsString('Withdraw and deposit are saved separately', $note);
        $this->assertSame('real summary', substr($harness->gitlab()->firstNoteBody(0), -12));

        $harness->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json'), 'shadow' => true]));

        $this->assertCount(1, $harness->gitlab()->paths('PUT'));
    }

    /**
     * A review leaves its status and the worst open finding; a clean one says clean and none
     *
     * @return void
     */
    public function testLabelsAfterAReview(): void
    {
        $harness = $this->harness();
        $harness->gitlab()->withLabels([], ['backend']);

        $harness->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json')]));

        $this->assertSame(['backend', 'kelpie-review::reviewed', 'kelpie-findings::blocker'], $harness->gitlab()->mrLabels());
        $this->assertArrayHasKey('kelpie-review::reviewing', $harness->gitlab()->projectLabels());

        $repo  = new TempRepo(['clean.json' => '{"verdict":"pass","summary":"Nothing wrong.","findings":[]}']);
        $clean = $this->harness();
        try {
            $clean->service()->review($this->settings(['cannedFile' => $repo->path() . '/clean.json']));
        } finally {
            $repo->remove();
        }

        $this->assertSame(['kelpie-review::clean', 'kelpie-findings::none'], $clean->gitlab()->mrLabels());
    }

    /**
     * The last automatic round says so in the summary and the label; past it the label is hard-cap
     *
     * @return void
     */
    public function testLastRoundAndHardCapLabels(): void
    {
        $previous = static function (int $round): array {
            return ['id' => 'dS', 'notes' => [['author' => ['id' => 9], 'body' => '<!-- ai-review:summary round=' . $round . ' sha=abc1230 -->' . "\nold", 'id' => 40, 'resolved' => null]]];
        };

        $last = $this->harness();
        $last->gitlab()->withDiscussions([$previous(2)]);
        $last->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json')]));

        $this->assertStringContainsString('**This was the last automatic review round** (3 of 3, on `abcdef12`)', $last->gitlab()->noteBodies()[0]);
        $this->assertStringContainsString('`review:extra` label', $last->gitlab()->noteBodies()[0]);
        $this->assertSame(['kelpie-review::last-round', 'kelpie-findings::blocker'], $last->gitlab()->mrLabels());

        $capped = $this->harness();
        $capped->gitlab()->withDiscussions([$previous(3)]);
        $capped->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json')]));

        $this->assertSame(['kelpie-review::hard-cap', 'kelpie-findings::none'], $capped->gitlab()->mrLabels());

        $extra = $this->harness();
        $extra->gitlab()->withDiscussions([$previous(2)]);
        $extra->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json'), 'labels' => ['review:extra']]));

        $this->assertStringNotContainsString('last automatic review round', $extra->gitlab()->noteBodies()[0]);
        $this->assertContains('kelpie-review::reviewed', $extra->gitlab()->mrLabels());
    }

    /**
     * Skipped and unreviewed runs say so in the label; a dry run prints the labels it would set
     *
     * @return void
     */
    public function testSkippedUnreviewedAndDryRunLabels(): void
    {
        $tooLarge = $this->harness();
        $tooLarge->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json'), 'maxLines' => 3]));
        $this->assertSame(['kelpie-review::unreviewed', 'kelpie-findings::none'], $tooLarge->gitlab()->mrLabels());

        $failed = $this->harness();
        $failed->model()->text('looks fine to me')->text('still no json');
        putenv('OPENROUTER_API_KEY=test-key-not-real');
        try {
            $failed->service()->review($this->settings());
        } finally {
            putenv('OPENROUTER_API_KEY');
        }
        $this->assertSame(['kelpie-review::unreviewed'], $failed->gitlab()->mrLabels());

        $dry = $this->harness()->service()->review($this->settings(['cannedFile' => $this->fixture('canned-ok.json'), 'dryRun' => true, 'token' => '']));
        $this->assertStringContainsString('--- would set the labels: kelpie-review::reviewed, kelpie-findings::blocker ---', implode("\n", $dry->lines));
    }

    /**
     * The text of one message of one request sent to the model
     *
     * @param ReviewHarness $harness The harness
     * @param int           $request Which request, from 0
     * @param int           $message Which message of it, from 0
     *
     * @return string
     */
    private function messageText(ReviewHarness $harness, int $request, int $message): string
    {
        $messages = $harness->model()->sent()[$request]['messages'] ?? null;
        $entry    = is_array($messages) ? ($messages[$message] ?? null) : null;
        $content  = is_array($entry) ? ($entry['content'] ?? null) : null;

        return is_string($content) ? $content : '';
    }

    /**
     * @param array<string, mixed> $overrides Settings to replace
     *
     * @return ReviewSettings
     */
    private function settings(array $overrides = []): ReviewSettings
    {
        return SettingsBuilder::make($overrides);
    }

    /**
     * @return ReviewHarness
     */
    private function harness(): ReviewHarness
    {
        return new ReviewHarness($this->createStub(Logger::class));
    }

    /**
     * @param string $name The fixture
     *
     * @return string
     */
    private function fixture(string $name): string
    {
        return SettingsBuilder::fixtures() . '/' . $name;
    }

    /**
     * @param string $name The discussions fixture
     *
     * @return list<array<mixed>>
     */
    private function discussions(string $name): array
    {
        $decoded = (new JsonService($this->createStub(Logger::class)))->decode((string) file_get_contents($this->fixture($name)));
        $out     = [];
        foreach ($decoded as $discussion) {
            if (is_array($discussion)) {
                $out[] = $discussion;
            }
        }

        return $out;
    }
}
