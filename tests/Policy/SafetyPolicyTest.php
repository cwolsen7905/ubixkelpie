<?php

declare(strict_types=1);

namespace Kelpie\Tests\Policy;

use Kelpie\DataTransferObject\FixProposal;
use Kelpie\DataTransferObject\TriageVerdict;
use Kelpie\Enum\TriageClass;
use Kelpie\Policy\SafetyPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Policy\SafetyPolicy
 *
 * What must hold:
 * - A fix inside every limit stands; any one violation downgrades it to needs_human
 * - Other classes, and a fix with no proposal yet, pass through unchanged
 *
 * @coversDefaultClass \Kelpie\Policy\SafetyPolicy
 */
final class SafetyPolicyTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(SafetyPolicy::class);
    }

    /**
     * A fix inside all limits stands
     *
     * @return void
     */
    public function testSafeFixStands(): void
    {
        $result = $this->policy()->apply($this->fix(), self::build());

        $this->assertSame(TriageClass::Fix, $result->class);
    }

    /**
     * Without a proposal the verdict is returned as is
     *
     * @return void
     */
    public function testVerdictWithoutProposalPassesThrough(): void
    {
        $verdict = $this->fix();

        $this->assertSame($verdict, $this->policy()->apply($verdict, null));
    }

    /**
     * Non-fix classes are never touched
     *
     * @return void
     */
    public function testNonFixVerdictPassesThrough(): void
    {
        $verdict = new TriageVerdict(TriageClass::NotAnIssue, 'preference');

        $this->assertSame($verdict, $this->policy()->apply($verdict, self::build(changedLines: 9999)));
    }

    /**
     * Every limit downgrades a fix
     *
     * @param FixProposal $proposal A proposal breaking one limit
     *
     * @return void
     */
    #[DataProvider('violations')]
    public function testViolationDowngradesToNeedsHuman(FixProposal $proposal): void
    {
        $result = $this->policy()->apply($this->fix(), $proposal);

        $this->assertSame(TriageClass::NeedsHuman, $result->class);
        $this->assertStringStartsWith('Not safe to fix automatically', $result->reason);
    }

    /**
     * Ordinary source and test paths are not forbidden
     *
     * @return void
     */
    public function testOrdinaryPathsAreAllowed(): void
    {
        $proposal = self::build(paths: ['php/Kelpie/Triage/TriageService.php', 'tests/Triage/TriageServiceTest.php', 'docs/ai-review.md', 'app/KelpieApi/src/Routes.php', 'app/KelpieApi/src/Dependencies.php']);
        $other    = self::build(paths: ['api/main.go', 'web/src/App.tsx', 'docs/Makefile.md']);

        $this->assertSame(TriageClass::Fix, $this->policy()->apply($this->fix(), $proposal)->class);
        $this->assertSame(TriageClass::Fix, $this->policy()->apply($this->fix(), $other)->class);
    }

    /**
     * A project's own globs: `*` stays in a folder, `**` crosses folders, a bare name matches anywhere, a folder covers what is under it
     *
     * @return void
     */
    public function testProjectProtectedGlobs(): void
    {
        $policy = $this->policy();

        $this->assertNull($policy->protectedPath(['a.go', 'web/src/App.tsx'], []));
        $this->assertSame('api/gen/x.go', $policy->protectedPath(['a.go', 'api/gen/x.go'], ['api/gen/**']));
        $this->assertSame('api/gen/x.go', $policy->protectedPath(['api/gen/x.go'], ['api/gen']));
        $this->assertNull($policy->protectedPath(['api/sub/gen.go'], ['api/*.go']));
        $this->assertSame('api/a.go', $policy->protectedPath(['api/a.go'], ['api/*.go']));
        $this->assertSame('web/deep/schema.json', $policy->protectedPath(['web/deep/schema.json'], ['schema.json']));
        $this->assertSame('v1.txt', $policy->protectedPath(['./v1.txt'], ['v?.txt']) === './v1.txt' ? 'v1.txt' : null);
        $this->assertNull($policy->protectedPath(['api/a.c'], ['api/*.go']));
    }

    /**
     * Proposals that each break exactly one limit
     *
     * @return array<string, array{FixProposal}>
     */
    public static function violations(): array
    {
        $cases = [
            'delete or rename'  => [self::build(deletesOrRenamesFiles: true)],
            'draft'             => [self::build(draft: true)],
            'fourth fix commit' => [self::build(mrFixCommits: 3)],
            'main target'       => [self::build(targetBranch: 'main')],
            'path traversal'    => [self::build(paths: ['php/../.gitlab-ci.yml'])],
            'third attempt'     => [self::build(threadAttempts: 2)],
            'too many files'    => [self::build(paths: ['a.php', 'b.php', 'c.php', 'd.php', 'e.php', 'f.php'])],
            'too many lines'    => [self::build(changedLines: 81)],
            'weakened tests'    => [self::build(weakensTests: true)],
        ];

        foreach (
            [
                '.gitlab-ci.yml', 'ci/build.sh', '.env', '.env.example', 'app/KelpieApi/.env.local', 'sql/migrations/001.sql',
                'composer.lock', 'web/package-lock.json', 'app/KelpieApi/k8s/dev-deploy.yaml', 'bin/promote.sh',
                'app/KelpieApi/dev-deploy.yaml', 'app/KelpieApi/main-ingress.yaml', 'app/KelpieApi/staging-service.yaml',
                'app/KelpieApi/dev-cronjob.yaml', 'sql/ubixkelpie.sql', 'sql/grants/app.sql',
                'go.sum', 'api/go.sum', 'Makefile', 'web/Makefile', 'Dockerfile', 'Dockerfile_Test', 'Dockerfile.prod', '.golangci.yml', '.eslintrc.json', 'eslint.config.mjs', 'web/tsconfig.json',
                'phpcs.xml', 'phpstan.neon', 'phpstan-baseline.neon', 'phpunit.xml.dist', 'config/secrets.php', 'deploy/server.pem',
            ] as $path
        ) {
            $cases['forbidden ' . $path] = [self::build(paths: ['php/Ok.php', $path])];
        }

        return $cases;
    }

    /**
     * @return SafetyPolicy
     */
    private function policy(): SafetyPolicy
    {
        return new SafetyPolicy($this->createStub(Logger::class));
    }

    /**
     * @return TriageVerdict
     */
    private function fix(): TriageVerdict
    {
        return new TriageVerdict(TriageClass::Fix, 'small change');
    }

    /**
     * An otherwise-safe proposal with overrides
     *
     * @param int      $changedLines          Added plus removed lines
     * @param string[] $paths                 Paths touched
     * @param bool     $deletesOrRenamesFiles Whether a file is deleted or renamed
     * @param bool     $weakensTests          Whether tests are weakened
     * @param int      $threadAttempts        Attempts already made on the thread
     * @param int      $mrFixCommits          Fix commits already on the MR
     * @param string   $targetBranch          The MR's target branch
     * @param bool     $draft                 Whether the MR is a draft
     *
     * @return FixProposal
     */
    private static function build(
        int $changedLines = 10,
        array $paths = ['php/Kelpie/Foo.php'],
        bool $deletesOrRenamesFiles = false,
        bool $weakensTests = false,
        int $threadAttempts = 0,
        int $mrFixCommits = 0,
        string $targetBranch = 'dev',
        bool $draft = false,
    ): FixProposal {
        return new FixProposal($changedLines, $paths, $deletesOrRenamesFiles, $weakensTests, $threadAttempts, $mrFixCommits, $targetBranch, $draft);
    }
}
