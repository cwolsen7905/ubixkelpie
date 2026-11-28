<?php

declare(strict_types=1);

namespace Kelpie\Tests\Autofix;

use Kelpie\Autofix\Attribution;
use Kelpie\DataTransferObject\Completion;
use Kelpie\DataTransferObject\TriageVerdict;
use Kelpie\Enum\ResponseOutcome;
use Kelpie\Enum\TriageClass;
use Kelpie\Service\Autofix\ReplyService;
use Kelpie\Service\Triage\UsageStampService;
use Kelpie\Tests\GitLab\FakeGitlab;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface as Logger;

/**
 * What must hold: an auto-fix thread reply made from a model call ends with the usage footer, above its marker, and
 * a reply with no model call behind it has none.
 */
#[CoversNothing]
final class ReplyUsageStampTest extends TestCase
{
    /**
     * A triage reply carries the footer before its marker
     *
     * @return void
     */
    public function testTriageReplyIsStamped(): void
    {
        $gitlab = new FakeGitlab();
        $gitlab->note('d1', 'x');
        $verdict = new TriageVerdict(TriageClass::Defer, 'later', new Completion('{}', 'm/x', 2500, 40, 0.002));

        $outcome = $this->service($gitlab)->respond('1', 3, 'd1', $verdict);

        $this->assertSame(ResponseOutcome::Replied, $outcome);
        $this->assertStringContainsString("\n\n_" . Attribution::signature() . " · agent `triage-model` · model `m/x`, effort default, 2.5k in / 40 out, \$0.0020 (OpenRouter)._\n\n<!-- kelpie:reply thread=d1 class=defer -->", $gitlab->notes()['d1'][1]['body']);
    }

    /**
     * Without a reported cost the reply shows tokens only
     *
     * @return void
     */
    public function testNoCostShowsTokensOnly(): void
    {
        $gitlab = new FakeGitlab();
        $gitlab->note('d1', 'x');

        $this->service($gitlab)->respond('1', 3, 'd1', new TriageVerdict(TriageClass::Defer, 'later', new Completion('{}', 'm/x', 7, 3)));

        $body = $gitlab->notes()['d1'][1]['body'];
        $this->assertStringContainsString('_' . Attribution::signature() . ' · agent `triage-model` · model `m/x`, effort default, 7 in / 3 out._', $body);
        $this->assertStringNotContainsString('$', $body);
    }

    /**
     * A refusal made without a model call has no footer
     *
     * @return void
     */
    public function testNoCallNoFooter(): void
    {
        $gitlab = new FakeGitlab();
        $gitlab->note('d1', 'x');

        $this->service($gitlab)->respond('1', 3, 'd1', new TriageVerdict(TriageClass::NeedsHuman, 'refused'));

        $this->assertStringNotContainsString('· model', $gitlab->notes()['d1'][1]['body']);
    }

    /**
     * A proposal carries the footer of the propose call
     *
     * @return void
     */
    public function testProposalIsStamped(): void
    {
        $gitlab = new FakeGitlab();
        $gitlab->note('d1', 'x');
        $sha = str_repeat('a', 40);

        $this->service($gitlab)->propose('1', 3, 'd1', TriageClass::Fix, $sha, 'Closes it.', "--- a/a\n+++ b/a\n", new Completion('{}', 'p/y', 900, 100, 0.01));

        $this->assertStringContainsString("\n\n_" . Attribution::signature() . " · agent `propose-model` · model `p/y`, effort default, 900 in / 100 out, \$0.0100 (OpenRouter)._\n\n<!-- kelpie:reply thread=d1 class=fix head=" . $sha . ' -->', $gitlab->notes()['d1'][1]['body']);
    }

    /**
     * A proposal the fixing agent made names the agent, and the model its run used
     *
     * @return void
     */
    public function testAgentProposalNamesTheAgent(): void
    {
        $gitlab = new FakeGitlab();
        $gitlab->note('d1', 'x');

        $this->service($gitlab)->propose('1', 3, 'd1', TriageClass::Fix, str_repeat('a', 40), 'Closes it.', "--- a/a\n+++ b/a\n", new Completion('{}', 'p/y', 900, 100, 0.01), null, Attribution::Fixer);

        $this->assertStringContainsString('_' . Attribution::signature() . ' · agent `fixer-agent` · model `p/y`,', $gitlab->notes()['d1'][1]['body']);
    }

    /**
     * A fix proposal's footer covers the triage call and the edit call together
     *
     * @return void
     */
    public function testProposalFooterSumsTriageAndEditCalls(): void
    {
        $gitlab = new FakeGitlab();
        $gitlab->note('d1', 'x');

        $this->service($gitlab)->propose('1', 3, 'd1', TriageClass::Fix, str_repeat('a', 40), 'Closes it.', "--- a/a\n+++ b/a\n", new Completion('{}', 'p/y', 900, 100, 0.01), new Completion('{}', 't/z', 100, 20, 0.0005));

        $this->assertStringContainsString('_' . Attribution::signature() . ' · agent `propose-model` · model `p/y`, effort default, 1.0k in / 120 out, $0.0105 (OpenRouter)._', $gitlab->notes()['d1'][1]['body']);
    }

    /**
     * @param FakeGitlab $gitlab The fake GitLab
     *
     * @return ReplyService
     */
    private function service(FakeGitlab $gitlab): ReplyService
    {
        $logger = $this->createStub(Logger::class);

        return new ReplyService($logger, $gitlab->writer($logger), 'kelpie-bot', new UsageStampService($logger));
    }
}
