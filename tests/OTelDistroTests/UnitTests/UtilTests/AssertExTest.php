<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests;

use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\DummyExceptionForTests;
use OTelDistroTests\Util\TestCaseBase;
use PHPUnit\Exception as PHPUnitExceptionInterface;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * @phpstan-type NoParamsReturnVoidCallable callable(): void
 * @phpstan-type ThrowableDerivedClassString class-string<Throwable>
 * @phpstan-type AssertThrowsImpl callable(ThrowableDerivedClassString, ?string, ?int, NoParamsReturnVoidCallable): void
 */
final class AssertExTest extends TestCaseBase
{
    /**
     * @phpstan-param ThrowableDerivedClassString $expectedThrowableClass
     * @phpstan-param NoParamsReturnVoidCallable $actualCodeThatShouldThrow
     */
    private function assertThrowsPhpUnitBuiltInImpl(string $expectedThrowableClass, ?string $expectedThrowableMessage, ?int $expectedThrowableCode, callable $actualCodeThatShouldThrow): void
    {
        $this->expectException($expectedThrowableClass);

        if ($expectedThrowableMessage !== null) {
            $this->expectExceptionMessage($expectedThrowableMessage);
        }
        if ($expectedThrowableCode !== null) {
            $this->expectExceptionCode($expectedThrowableCode);
        }

        $actualCodeThatShouldThrow();
    }

    /**
     * @phpstan-param AssertThrowsImpl $assertThrowsImpl
     */
    private static function verifyAssertThrowsImpl(callable $assertThrowsImpl, string $exceptionMessage, int $exceptionCode): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        foreach ([true, false] as $actuallyThrows) {
            foreach ([$exceptionMessage, $exceptionMessage . ' suffix to cause mismatch'] as $actualMessage) {
                foreach ([$exceptionCode, $exceptionCode + 1] as $actualCode) {
                    $shouldExpectFailure = (!$actuallyThrows) || ($exceptionMessage !== $actualMessage) || ($exceptionCode !== $actualCode);
                    try {
                        $assertThrowsImpl(
                            DummyExceptionForTests::class,
                            $exceptionMessage,
                            $exceptionCode,
                            static function () use ($actuallyThrows, $actualMessage, $actualCode): void {
                                if ($actuallyThrows) {
                                    throw new DummyExceptionForTests($actualMessage, $actualCode);
                                }
                            },
                        );
                        self::assertFalse($shouldExpectFailure);
                    } catch (PHPUnitExceptionInterface $ex) {
                        $dbgCtx->add(compact('ex'));
                        self::assertTrue($shouldExpectFailure);
                    }
                }
            }
        }
    }

    public function testThrows(): void
    {
        /** @phpstan-var string $exceptionMessage */
        static $exceptionMessage = 'Dummy message to test assert-throws implementations';
        /** @phpstan-var int $exceptionCode */
        static $exceptionCode = 321;

        self::verifyAssertThrowsImpl(AssertEx::throwsWithMessageCode(...), $exceptionMessage, $exceptionCode);

        // Run the same verification of PHPUnit's built-in way to assert that some code throws
        self::verifyAssertThrowsImpl(self::assertThrowsPhpUnitBuiltInImpl(...), $exceptionMessage, $exceptionCode);

        /**
         * @phpstan-param ThrowableDerivedClassString $expectedThrowableClass
         * @phpstan-param NoParamsReturnVoidCallable $actualCodeThatShouldThrow
         */
        $incorrectAssertThrowsImpl = function (string $expectedThrowableClass, ?string $expectedThrowableMessage, ?int $expectedThrowableCode, callable $actualCodeThatShouldThrow): void {
            $actualCodeThatShouldThrow();
        };
        AssertEx::throws(PHPUnitExceptionInterface::class, fn() => self::verifyAssertThrowsImpl($incorrectAssertThrowsImpl, $exceptionMessage, $exceptionCode));

        /**
         * @phpstan-param ThrowableDerivedClassString $expectedThrowableClass
         * @phpstan-param NoParamsReturnVoidCallable $actualCodeThatShouldThrow
         */
        $incorrectAssertThrowsImpl = function (string $expectedThrowableClass, ?string $expectedThrowableMessage, ?int $expectedThrowableCode, callable $actualCodeThatShouldThrow): void {
            try {
                $actualCodeThatShouldThrow();
            } /** @noinspection PhpUnusedLocalVariableInspection */ catch (DummyExceptionForTests $_) {
            }
        };
        AssertEx::throws(PHPUnitExceptionInterface::class, fn() => self::verifyAssertThrowsImpl($incorrectAssertThrowsImpl, $exceptionMessage, $exceptionCode));
    }

    public static function testNotEmptyString(): void
    {
        AssertEx::throws(PHPUnitExceptionInterface::class, fn() => AssertEx::notEmptyString(''));

        AssertEx::notEmptyString('a');
        AssertEx::notEmptyString('abc');
        AssertEx::notEmptyString(' ');
        AssertEx::notEmptyString('0');
        AssertEx::notEmptyString('0.0');
        AssertEx::notEmptyString('1');

        // Compare to PHPUnit's Assert::assertNotEmpty

        // Corrent cases:
        Assert::assertEmpty(''); // @phpstan-ignore staticMethod.alreadyNarrowedType
        AssertEx::throws(PHPUnitExceptionInterface::class, fn() => Assert::assertNotEmpty('')); // @phpstan-ignore staticMethod.impossibleType
        Assert::assertNotEmpty('abc'); // @phpstan-ignore staticMethod.alreadyNarrowedType
        Assert::assertNotEmpty(' '); // @phpstan-ignore staticMethod.alreadyNarrowedType
        Assert::assertNotEmpty('0.0'); // @phpstan-ignore staticMethod.alreadyNarrowedType
        Assert::assertNotEmpty('1'); // @phpstan-ignore staticMethod.alreadyNarrowedType

        // Incorrent cases:
        Assert::assertEmpty('0'); // @phpstan-ignore staticMethod.alreadyNarrowedType
        AssertEx::throws(PHPUnitExceptionInterface::class, fn() => Assert::assertNotEmpty('0')); // @phpstan-ignore staticMethod.impossibleType

        // Cases on non-string values

        Assert::assertEmpty(null); // @phpstan-ignore staticMethod.alreadyNarrowedType
        /** @noinspection PhpUnitAssertCanBeReplacedWithEmptyInspection */
        Assert::assertTrue(empty($undefinedVariable)); // @phpstan-ignore staticMethod.alreadyNarrowedType, empty.variable
    }
}
