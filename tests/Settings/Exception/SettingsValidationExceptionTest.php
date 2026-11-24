<?php

declare(strict_types=1);

namespace Kelpie\Tests\Settings\Exception;

use Kelpie\Settings\Exception\SettingsValidationException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Ubix\Tests\AbstractUbixConcreteClassOrEnumTestCase as UbixConcreteClassOrEnumTestCase;
use Ubix\Tests\UbixConcreteClassOrEnumTestCaseInterface as IUbixConcreteClassOrEnumTestCase;

/**
 * PHPUnit test case for \Kelpie\Settings\Exception\SettingsValidationException
 *
 * @coversDefaultClass \Kelpie\Settings\Exception\SettingsValidationException
 */
#[AllowMockObjectsWithoutExpectations]
final class SettingsValidationExceptionTest extends UbixConcreteClassOrEnumTestCase implements IUbixConcreteClassOrEnumTestCase
{
    /**
     * Test that the class is following uBix standards
     *
     * @return void
     */
    public function testFollowingUbixStandards(): void
    {
        $this->testClassFollowingUbixStandards(SettingsValidationException::class);
    }

    /**
     * Test that the errors are kept and counted
     *
     * @return void
     */
    public function testKeepsTheErrors(): void
    {
        $errors    = [['field' => 'mode', 'message' => 'bad']];
        $exception = new SettingsValidationException('bad', 0, $errors);

        $this->assertSame($errors, $exception->getErrors());
        $this->assertSame('bad', $exception->getMessage());
    }
}
