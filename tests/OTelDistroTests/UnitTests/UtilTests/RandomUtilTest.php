<?php

/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests;

use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\RandomUtil;
use OTelDistroTests\Util\TestCaseBase;

class RandomUtilTest extends TestCaseBase
{
    public function testArrayRandValues(): void
    {
        self::assertSame([], RandomUtil::arrayRandValues([], 0));
        self::assertSame([], RandomUtil::arrayRandValues(['a'], 0));
        self::assertSame(['a'], RandomUtil::arrayRandValues(['a'], 1));

        $totalSet = ['a', 'b'];
        $randSelectedSubSet = RandomUtil::arrayRandValues($totalSet, 1);
        self::assertTrue($randSelectedSubSet == ['a'] || $randSelectedSubSet == ['b'], LoggableToString::convert(compact('randSelectedSubSet')));
        AssertEx::arrayValuesIsSubsetOf($randSelectedSubSet, $totalSet);
        self::assertEqualsCanonicalizing($totalSet, RandomUtil::arrayRandValues($totalSet, count($totalSet)));

        $totalSet = ['a', 'b', 'c'];
        $randSelectedSubSet = RandomUtil::arrayRandValues($totalSet, 1);
        self::assertCount(1, $randSelectedSubSet);
        self::assertTrue($randSelectedSubSet == ['a'] || $randSelectedSubSet == ['b'] || $randSelectedSubSet == ['c'], LoggableToString::convert(compact('randSelectedSubSet')));
        AssertEx::arrayValuesIsSubsetOf($randSelectedSubSet, $totalSet);
        $randSelectedSubSet = RandomUtil::arrayRandValues($totalSet, 2);
        self::assertCount(2, $randSelectedSubSet);
        AssertEx::arrayValuesIsSubsetOf($randSelectedSubSet, $totalSet);
        self::assertEqualsCanonicalizing($totalSet, RandomUtil::arrayRandValues($totalSet, count($totalSet)));
    }
}
