<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Review;

use Kelpie\DataTransferObject\Review\ReviewResult;
use Kelpie\Enum\Review\Confidence;
use Kelpie\Enum\Review\Severity;
use Kelpie\Service\Review\ReviewParserService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Review\ReviewParserService
 *
 * What must hold:
 * - A bare JSON reply, a fenced one and one wrapped in prose all parse
 * - No JSON gives a retry note that says so; a schema problem names the first problem
 * - A finding's id is the same for the same file and slug, whatever its case, line or wording
 * - A line that is missing, null, fractional or negative becomes a whole number, 0 meaning not about one line
 *
 * @coversDefaultClass \Kelpie\Service\Review\ReviewParserService
 */
final class ReviewParserServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(ReviewParserService::class);
    }

    /**
     * A bare, a fenced and a prose-wrapped reply all give the review
     *
     * @return void
     */
    public function testParsesBareFencedAndProseReplies(): void
    {
        foreach (['canned-ok.json', 'canned-fenced.txt', 'canned-prose.txt'] as $name) {
            $result = $this->parser()->parse((string) file_get_contents(SettingsBuilder::fixtures() . '/' . $name));

            $this->assertInstanceOf(ReviewResult::class, $result, $name);
            $this->assertSame('block', $result->verdict);
            $this->assertCount(3, $result->findings);
        }
    }

    /**
     * The finding is typed and carries its stable id
     *
     * @return void
     */
    public function testFindingFields(): void
    {
        $result = $this->parser()->parse((string) file_get_contents(SettingsBuilder::fixtures() . '/canned-ok.json'));

        $this->assertInstanceOf(ReviewResult::class, $result);
        $finding = $result->findings[0];
        $this->assertSame([Severity::Blocker, Confidence::High, 'src/Transfer.php', 15, 'non-atomic-transfer-saves'], [$finding->severity, $finding->confidence, $finding->file, $finding->line, $finding->fingerprint]);
        $this->assertSame($this->parser()->idFor('src/Transfer.php', 'non-atomic-transfer-saves'), $finding->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $finding->id);
    }

    /**
     * Prose with no object, and garbage, give the retry note
     *
     * @return void
     */
    public function testNoJsonGivesTheRetryNote(): void
    {
        foreach (['Sure! Here is my review: looks fine.', '{not json'] as $reply) {
            $result = $this->parser()->parse($reply);

            $this->assertIsString($result);
            $this->assertStringContainsString('contained no JSON object', $result);
        }
    }

    /**
     * Each schema break names itself
     *
     * @return void
     */
    public function testSchemaProblemsAreNamed(): void
    {
        $finding = '"severity":"minor","confidence":"high","file":"a.php","line":1,"title":"t","why":"w","fingerprint":"f"';
        $cases   = [
            '{"verdict":"maybe","summary":"s","findings":[]}'                                                                        => 'verdict must be one of pass, concerns, block',
            '{"verdict":"pass","summary":"s","findings":["x"]}'                                                                      => 'findings[0] must be an object',
            '{"verdict":"pass","summary":"s","findings":[{' . str_replace('"a.php"', '""', $finding) . '}]}'                         => 'findings[0].file must be a non-empty string',
            '{"verdict":"pass","summary":"s","findings":[{' . str_replace('"fingerprint":"f"', '"fingerprint":""', $finding) . '}]}' => 'findings[0].fingerprint must be a non-empty string',
            '{"verdict":"pass","summary":"s","findings":[{' . str_replace('"high"', '"sure"', $finding) . '}]}'                      => 'findings[0].confidence must be one of',
            '{"verdict":"pass","summary":"s","findings":[{' . str_replace('"line":1', '"line":"x"', $finding) . '}]}'                => 'findings[0].line must be a number or null',
            '{"verdict":"pass","summary":"s","findings":[{' . str_replace('"minor"', '"huge"', $finding) . '}]}'                     => 'findings[0].severity must be one of',
            '{"verdict":"pass","summary":"s","findings":[{' . str_replace('"title":"t"', '"title":""', $finding) . '}]}'             => 'findings[0].title must be a non-empty string',
            '{"verdict":"pass","summary":"s","findings":[{' . str_replace('"why":"w"', '"why":1', $finding) . '}]}'                  => 'findings[0].why must be a string',
            '{"verdict":"pass","summary":"s"}'                                                                                       => 'findings must be an array',
            '{"verdict":"pass","summary":5,"findings":[]}'                                                                           => 'summary must be a string',
        ];

        foreach ($cases as $reply => $problem) {
            $result = $this->parser()->parse($reply);
            $this->assertIsString($result, $reply);
            $this->assertStringContainsString('did not match the schema: ' . $problem, $result);
        }
    }

    /**
     * A later candidate that is valid wins over an earlier invalid one
     *
     * @return void
     */
    public function testFirstValidCandidateWins(): void
    {
        $reply = 'draft {"verdict":"nope"} final {"verdict":"pass","summary":"fine","findings":[]}';

        $result = $this->parser()->parse($reply);

        $this->assertInstanceOf(ReviewResult::class, $result);
        $this->assertSame('pass', $result->verdict);
    }

    /**
     * The id ignores case, line and wording
     *
     * @return void
     */
    public function testFindingIdIsStable(): void
    {
        $this->assertSame($this->parser()->idFor('a.php', 'Null-Lookup'), $this->parser()->idFor('a.php', 'null-lookup'));
        $this->assertNotSame($this->parser()->idFor('a.php', 'x'), $this->parser()->idFor('b.php', 'x'));
    }

    /**
     * A missing, null, fractional or negative line is a whole number
     *
     * @return void
     */
    public function testLineBecomesAWholeNumber(): void
    {
        $base  = '"severity":"minor","confidence":"low","file":"a.php","title":"t","why":"w","fingerprint":"f"';
        $lines = [''  => 0, ',"line":null' => 0, ',"line":7.9' => 7, ',"line":-3' => -3, ',"line":12' => 12];

        foreach ($lines as $json => $expected) {
            $result = $this->parser()->parse('{"verdict":"concerns","summary":"s","findings":[{' . $base . $json . '}]}');
            $this->assertInstanceOf(ReviewResult::class, $result);
            $this->assertSame($expected, $result->findings[0]->line);
        }
    }

    /**
     * @return ReviewParserService
     */
    private function parser(): ReviewParserService
    {
        return new ReviewParserService($this->createStub(Logger::class), new JsonService($this->createStub(Logger::class)));
    }
}
