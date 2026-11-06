<?php

declare(strict_types=1);

namespace Kelpie\Tests\Service\Autofix;

use Kelpie\Service\Autofix\StoredEditsService;
use Psr\Log\LoggerInterface as Logger;
use Ubix\Service\JsonService;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Service\Autofix\StoredEditsService
 *
 * What must hold:
 * - A stored list of edits reads back as edits
 * - Anything else (not JSON, not a list of edits, an edit missing a field) reads as no edits
 *
 * @coversDefaultClass \Kelpie\Service\Autofix\StoredEditsService
 */
final class StoredEditsServiceTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(StoredEditsService::class);
    }

    /**
     * Edits read back
     *
     * @return void
     */
    public function testEditsReadBack(): void
    {
        $edits = $this->service()->decode('[{"file":"a.txt","old":"one","new":"two"}]');

        $this->assertCount(1, $edits);
        $this->assertSame('a.txt', $edits[0]->file);
        $this->assertSame('one', $edits[0]->old);
        $this->assertSame('two', $edits[0]->new);
    }

    /**
     * Anything unreadable is none
     *
     * @return void
     */
    public function testUnreadableIsNone(): void
    {
        foreach (['not json', '[1]', '[{"file":"a","old":"b"}]', '[{"file":"a","old":"b","new":"c"},{"file":"x"}]'] as $json) {
            $this->assertSame([], $this->service()->decode($json), $json);
        }
    }

    /**
     * @return StoredEditsService
     */
    private function service(): StoredEditsService
    {
        $logger = $this->createStub(Logger::class);

        return new StoredEditsService($logger, new JsonService($logger));
    }
}
