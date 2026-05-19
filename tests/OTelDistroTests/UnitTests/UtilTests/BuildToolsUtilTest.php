<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests;

use OpenTelemetry\Distro\Log\LogFeature;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\TestCaseBase;
use OpenTelemetry\DistroTools\Build\BuildToolsLogUtil;
use ReflectionClass;

final class BuildToolsUtilTest extends TestCaseBase
{
    public static function testProdLogFeatureValueToNameMap(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $assertValueToName = function (int $value, string $expectedName): void {
            self::assertSame($expectedName, BuildToolsLogUtil::prodLogFeatureIntToString($value));
        };

        $assertValueToName(LogFeature::ALL, 'ALL');
        $assertValueToName(LogFeature::CONFIG, 'CONFIG');

        $logFeatureReflClass = new ReflectionClass(LogFeature::class);
        $constsNameToVal = $logFeatureReflClass->getConstants();
        foreach ($constsNameToVal as $constName => $constVal) {
            $assertValueToName(AssertEx::isInt($constVal), $constName);
        }
        /** @var array<string, int> $constsNameToVal */

        // Verify strings generated for not predefined int values
        $maxPredefinedIntVal = max(AssertEx::notEmptyList(array_values($constsNameToVal)));
        foreach ([1, 12, 321, 4567] as $delta) {
            $notPredefinedFeatureIntVal = $maxPredefinedIntVal + $delta;
            $assertValueToName($notPredefinedFeatureIntVal, 'UNKNOWN FEATURE ' . $notPredefinedFeatureIntVal);
        }
    }
}
