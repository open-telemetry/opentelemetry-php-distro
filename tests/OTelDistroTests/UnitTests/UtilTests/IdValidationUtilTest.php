<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests;

use OTelDistroTests\Util\IdValidationUtil;
use OTelDistroTests\Util\Log\LoggableToString;
use PHPUnit\Framework\TestCase;

class IdValidationUtilTest extends TestCase
{
    /**
     * @return array<array{string, int, bool}>
     */
    public static function dataProviderForTestIsValidHexNumberString(): array
    {
        /** @noinspection SpellCheckingInspection */
        return [
            ['1234', 2, true],
            ['1234', 3, false],
            ['1234', 1, false],
            ['abcdef', 3, true],
            ['AbCdEf', 3, true],
            ['0123456789AbCdEf', 8, true],
            ['abcd', 2, true],
            ['zabc', 2, false],
            ['abcz', 2, false],
        ];
    }

    /**
     * @dataProvider dataProviderForTestIsValidHexNumberString
     */
    public function testIsValidHexNumberString(
        string $numberAsString,
        int $expectedSizeInBytes,
        bool $expectedResult
    ): void {
        $actualResult = IdValidationUtil::isValidHexNumberString($numberAsString, $expectedSizeInBytes);
        self::assertSame($expectedResult, $actualResult, LoggableToString::convert(compact('numberAsString', 'expectedSizeInBytes', 'expectedResult', 'actualResult')));
    }
}
