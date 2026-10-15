<?php

declare(strict_types=1);

namespace Kelpie\Tests\DataTransferObject;

use DateTimeImmutable;
use Kelpie\DataTransferObject\Thread;
use ReflectionClass;
use Ubix\DataTransferObject\DtoInterface as Dto;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\DataTransferObject\Thread
 *
 * @coversDefaultClass \Kelpie\DataTransferObject\Thread
 */
final class ThreadTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(Thread::class);
    }

    /**
     * Thread is immutable: properties are readonly and public
     *
     * @return void
     */
    public function testThreadIsImmutable(): void
    {
        $class = new ReflectionClass(Thread::class);

        $this->assertTrue($class->isReadOnly());
        $this->assertTrue($class->implementsInterface(Dto::class));
        $this->assertTrue($class->hasMethod('__construct') && $class->getMethod('__construct')->isPublic());
        $this->assertCount(1, $class->getMethods());

        foreach ($class->getProperties() as $property) {
            $this->assertTrue($property->isPublic());
            $this->assertTrue($property->isReadOnly());
            $this->assertTrue($property->getType() !== null);
        }
    }

    /**
     * Thread properties are set correctly from the constructor
     *
     * @return void
     */
    public function testThreadPropertiesAreSetFromConstructor(): void
    {
        $createdAt = new DateTimeImmutable('2026-10-01T10:00:00Z');
        $updatedAt = new DateTimeImmutable('2026-10-01T12:00:00Z');

        $thread = new Thread(
            discussionId: 42,
            firstNoteId:  100,
            author:       'bob',
            body:         'Use a typed property.',
            filePath:     'src/processor.php',
            line:         42,
            resolved:     false,
            createdAt:    $createdAt,
            updatedAt:    $updatedAt,
            noteCount:    2,
        );

        $this->assertSame(42, $thread->discussionId);
        $this->assertSame(100, $thread->firstNoteId);
        $this->assertSame('bob', $thread->author);
        $this->assertSame('Use a typed property.', $thread->body);
        $this->assertSame('src/processor.php', $thread->filePath);
        $this->assertSame(42, $thread->line);
        $this->assertFalse($thread->resolved);
        $this->assertSame($createdAt, $thread->createdAt);
        $this->assertSame($updatedAt, $thread->updatedAt);
        $this->assertSame(2, $thread->noteCount);
    }

    /**
     * Diff note fields are null for general notes
     *
     * @return void
     */
    public function testDiffNoteFieldsAreNullForGeneralNotes(): void
    {
        $thread = new Thread(
            discussionId: 5,
            firstNoteId:  106,
            author:       'dave',
            body:         'Please add a return type hint.',
            filePath:     null,
            line:         null,
            resolved:     false,
            createdAt:    new DateTimeImmutable(),
            updatedAt:    new DateTimeImmutable(),
            noteCount:    1,
        );

        $this->assertNull($thread->filePath);
        $this->assertNull($thread->line);
    }
}
