<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests\ProdLogTests;

use OpenTelemetry\Distro\Log\LogBackend;
use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\DataProviderForTestBuilder;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\MixedMap;
use OTelDistroTests\Util\TestCaseBase;

class LogBackendTest extends TestCaseBase
{
    private const MAX_ENABLED_LEVEL_KEY = 'max_enabled_level';

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestConfigureMaxEnabledLevel(): iterable
    {
        $allValidLogLevelValues = array_map(fn($logLevel) => $logLevel->value, LogLevel::cases());
        $maxValidValue = max($allValidLogLevelValues);
        $invalidLogLevelValues = [-1, max($allValidLogLevelValues) + 1, $maxValidValue + 100, -$maxValidValue];

        return self::adaptDataProviderForTestBuilderToSmokeToDescToMixedMap(
            (new DataProviderForTestBuilder())
                ->addKeyedDimensionAllValuesCombinable(self::MAX_ENABLED_LEVEL_KEY, array_merge($allValidLogLevelValues, $invalidLogLevelValues))
        );
    }

    /**
     * @dataProvider dataProviderForTestConfigureMaxEnabledLevel
     */
    public function testConfigureMaxEnabledLevel(MixedMap $testArgs): void
    {
        $maxEnabledLevel = AssertEx::notNull($testArgs->getInt(self::MAX_ENABLED_LEVEL_KEY));

        LogBackendTestUtil::saveActOnTempInstanceRestore(
            new LogBackend(maxEnabledLevel: $maxEnabledLevel, sourceCodeRootDirs: []),
            function () use ($maxEnabledLevel): void {
                DebugContext::getCurrentScope(/* out */ $dbgCtx);

                $expectedMaxEnabledLevel = LogLevel::tryFrom($maxEnabledLevel) ?? LogBackend::getDefaultMaxEnabledLevel();
                $dbgCtx->add(compact('expectedMaxEnabledLevel'));
                self::assertSame($expectedMaxEnabledLevel, LogBackend::getSingletonInstance()->maxEnabledLevel);
            },
        );
    }
}
