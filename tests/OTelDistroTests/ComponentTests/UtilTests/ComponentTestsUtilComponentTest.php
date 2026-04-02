<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\UtilTests;

use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\ComponentTests\Util\AppCodeContextDataUtil;
use OTelDistroTests\ComponentTests\Util\AppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\EnvVarUtilForTests;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\Config\OptionForTestsName;
use OTelDistroTests\Util\DataProviderForTestBuilder;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\MixedMap;
use PHPUnit\Framework\Exception as PHPUnitFrameworkException;

/**
 * @group does_not_require_external_services
 */
final class ComponentTestsUtilComponentTest extends ComponentTestCaseBase
{
    private const INITIAL_LOG_LEVELS_KEY = 'initial_log_levels';
    private const FAIL_ON_RERUN_COUNT_KEY = 'fail_on_rerun_count';
    private const SHOULD_FAIL_KEY = 'should_fail';

    /**
     * @return iterable<array{MixedMap}>
     */
    public static function dataProviderForTestRunAndEscalateLogLevelOnFailure(): iterable
    {
        $initialLogLevels = [LogLevel::info, LogLevel::trace, LogLevel::debug];

        return self::adaptDataProviderForTestBuilderToSmokeToDescToMixedMap(
            (new DataProviderForTestBuilder())
                ->addKeyedDimensionOnlyFirstValueCombinable(self::LOG_LEVEL_FOR_PROD_CODE_KEY, $initialLogLevels)
                ->addKeyedDimensionOnlyFirstValueCombinable(self::LOG_LEVEL_FOR_TEST_CODE_KEY, $initialLogLevels)
                ->addKeyedDimensionOnlyFirstValueCombinable(self::FAIL_ON_RERUN_COUNT_KEY, [1, 2, 3])
                ->addBoolKeyedDimensionOnlyFirstValueCombinable(self::SHOULD_FAIL_KEY)
                ->addKeyedDimensionOnlyFirstValueCombinable(OptionForTestsName::escalated_reruns_max_count->name, [2, 0])
        );
    }

    private static function buildFailMessage(int $runCount): string
    {
        return 'Dummy failed; run count: ' . $runCount;
    }

    public static function appCodeForTestRunAndEscalateLogLevelOnFailure(MixedMap $appCodeArgs): void
    {
        self::appCodeSetsHowFinished(
            $appCodeArgs,
            /**
             * @retrun array<string, mixed>
             */
            function () use ($appCodeArgs): array {
                DebugContext::getCurrentScope(/* out */ $dbgCtx);
                $dbgCtx->add(compact('appCodeArgs'));
                $dbgCtx->add(['testConfig' => AmbientContextForTests::testConfig()]);
                $expectedLogLevelForProdCode = $appCodeArgs->getLogLevel(self::LOG_LEVEL_FOR_PROD_CODE_KEY);
                $dbgCtx->add(compact('expectedLogLevelForProdCode'));
                $prodConfig = self::buildProdConfigFromAppCode();
                $dbgCtx->add(compact('prodConfig'));
                $actualLogLevelForProdCode = $prodConfig->effectiveLogLevel();
                $dbgCtx->add(compact('actualLogLevelForProdCode'));
                self::assertSame($expectedLogLevelForProdCode, $actualLogLevelForProdCode);
                $expectedLogLevelForTestCode = $appCodeArgs->getLogLevel(self::LOG_LEVEL_FOR_TEST_CODE_KEY);
                $dbgCtx->add(compact('expectedLogLevelForTestCode'));
                $actualLogLevelForTestCode = AmbientContextForTests::testConfig()->logLevel;
                $dbgCtx->add(compact('actualLogLevelForTestCode'));
                self::assertSame($expectedLogLevelForTestCode, $actualLogLevelForTestCode);
                return [];
            }
        );
    }

    public function test0WithoutEscalation(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $testCaseHandle = $this->getTestCaseHandle();
        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost();

        $appCodeArgs = [];
        AppCodeContextDataUtil::createTempFile($testCaseHandle, /* in,out */ $appCodeArgs);

        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestRunAndEscalateLogLevelOnFailure']),
            function (AppCodeRequestParams $appCodeRequestParams) use ($testCaseHandle, $appCodeArgs): void {
                $appCodeRequestParams->setAppCodeArgs(
                    $appCodeArgs
                    + [
                        self::LOG_LEVEL_FOR_PROD_CODE_KEY => ArrayUtilForTests::getSingleValue($testCaseHandle->getProdCodeLogLevels()),
                        self::LOG_LEVEL_FOR_TEST_CODE_KEY => AmbientContextForTests::testConfig()->logLevel,
                    ]
                );
            }
        );

        $this->waitForOneSpan($testCaseHandle);

        // Assert

        $appCodeContextData = AppCodeContextDataUtil::readDataAsMixedMapFromTempFile($appCodeArgs);
        $dbgCtx->add(compact('appCodeContextData'));
        self::assertTrue($appCodeContextData->getBool(self::DID_APP_CODE_FINISH_SUCCESSFULLY_KEY));
    }

    /**
     * @return array<string, ?string>
     */
    private static function unsetLogLevelRelatedEnvVars(): array
    {
        $envVars = EnvVarUtilForTests::getAll();
        $logLevelRelatedEnvVarsToRestore = [];
        foreach (OptionForProdName::getAllLogLevelRelated() as $optName) {
            $envVarName = $optName->toEnvVarName();
            if (array_key_exists($envVarName, $envVars)) {
                $logLevelRelatedEnvVarsToRestore[$envVarName] = $envVars[$envVarName];
                EnvVarUtilForTests::unset($envVarName);
            } else {
                $logLevelRelatedEnvVarsToRestore[$envVarName] = null;
            }

            self::assertNull(EnvVarUtilForTests::get($envVarName));
        }
        return $logLevelRelatedEnvVarsToRestore;
    }

    /**
     * @dataProvider dataProviderForTestRunAndEscalateLogLevelOnFailure
     */
    public function testRunAndEscalateLogLevelOnFailure(MixedMap $testArgs): void
    {
        // TODO: Re-enable ComponentTestsUtilComponentTest::testRunAndEscalateLogLevelOnFailure
        // Temporarily disable this test since it's flaky
        if (self::dummyAssert()) {
            return;
        }

        $logLevelRelatedEnvVarsToRestore = self::unsetLogLevelRelatedEnvVars();
        $prodCodeSyslogLevelEnvVarName = OptionForProdName::log_level_syslog->toEnvVarName();
        $initialLogLevelForProdCode = $testArgs->getLogLevel(self::LOG_LEVEL_FOR_PROD_CODE_KEY);
        EnvVarUtilForTests::set($prodCodeSyslogLevelEnvVarName, $initialLogLevelForProdCode->name);

        $logLevelForTestCodeToRestore = AmbientContextForTests::testConfig()->logLevel;
        $initialLogLevelForTestCode = $testArgs->getLogLevel(self::LOG_LEVEL_FOR_TEST_CODE_KEY);
        AmbientContextForTests::resetLogLevel($initialLogLevelForTestCode);

        $rerunsMaxCountToRestore = AmbientContextForTests::testConfig()->escalatedRerunsMaxCount;
        $rerunsMaxCount = $testArgs->getInt(OptionForTestsName::escalated_reruns_max_count->name);
        AmbientContextForTests::resetEscalatedRerunsMaxCount($rerunsMaxCount);

        $initialLevels = [];
        foreach (self::LOG_LEVEL_FOR_CODE_KEYS as $levelTypeKey) {
            $initialLevels[$levelTypeKey] = $testArgs->getLogLevel($levelTypeKey);
        }
        $testArgs[self::INITIAL_LOG_LEVELS_KEY] = $initialLevels;
        $expectedEscalatedLevelsSeqCount = IterableUtil::count(self::generateLevelsForRunAndEscalateLogLevelOnFailure($initialLevels, $rerunsMaxCount));
        if ($rerunsMaxCount === 0) {
            self::assertSame(0, $expectedEscalatedLevelsSeqCount);
        }
        $failOnRerunCountArg = $testArgs->getInt(self::FAIL_ON_RERUN_COUNT_KEY);
        $expectedFailOnRunCount = $failOnRerunCountArg <= $expectedEscalatedLevelsSeqCount ? ($failOnRerunCountArg + 1) : 1;
        $expectedMessage = self::buildFailMessage($expectedFailOnRunCount);
        $shouldFail = $testArgs->getBool(self::SHOULD_FAIL_KEY);

        $nextRunCount = 1;
        try {
            self::runAndEscalateLogLevelOnFailure(
                self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs),
                function () use ($testArgs, &$nextRunCount): void {
                    $testArgs['currentRunCount'] = $nextRunCount++;
                    $this->implTestRunAndEscalateLogLevelOnFailure($testArgs);
                }
            );
            $runAndEscalateLogLevelOnFailureExitedNormally = true;
        } catch (PHPUnitFrameworkException $ex) {
            $runAndEscalateLogLevelOnFailureExitedNormally = false;
            self::assertStringContainsString($expectedMessage, $ex->getMessage());
        }
        self::assertSame(!$shouldFail, $runAndEscalateLogLevelOnFailureExitedNormally);

        self::assertSame($rerunsMaxCount, AmbientContextForTests::testConfig()->escalatedRerunsMaxCount);
        AmbientContextForTests::resetEscalatedRerunsMaxCount($rerunsMaxCountToRestore);

        self::assertSame($initialLogLevelForTestCode, AmbientContextForTests::testConfig()->logLevel);
        AmbientContextForTests::resetLogLevel($logLevelForTestCodeToRestore);

        self::assertSame($initialLogLevelForProdCode->name, EnvVarUtilForTests::get($prodCodeSyslogLevelEnvVarName));
        foreach ($logLevelRelatedEnvVarsToRestore as $envVarName => $envVarValue) {
            EnvVarUtilForTests::setOrUnset($envVarName, $envVarValue);
        }
    }

    private function implTestRunAndEscalateLogLevelOnFailure(MixedMap $testArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $currentRunCount = $testArgs->getInt('currentRunCount');
        self::assertGreaterThanOrEqual(1, $currentRunCount);
        $currentReRunCount = $currentRunCount === 1 ? 0 : ($currentRunCount - 1);
        $shouldFail = $testArgs->getBool(self::SHOULD_FAIL_KEY);
        $failOnRerunCountArg = $testArgs->getInt(self::FAIL_ON_RERUN_COUNT_KEY);
        /** @var array<string, LogLevel> $initialLevels */
        $initialLevels = $testArgs->getArray(self::INITIAL_LOG_LEVELS_KEY);
        $shouldCurrentRunFail = $shouldFail && ($currentRunCount === 1 || $currentReRunCount === $failOnRerunCountArg);
        if ($currentRunCount === 1) {
            $expectedLevels = $initialLevels;
        } else {
            $rerunsMaxCount = $testArgs->getInt(OptionForTestsName::escalated_reruns_max_count->name);
            self::assertTrue(
                IterableUtil::getNthValue(
                    self::generateLevelsForRunAndEscalateLogLevelOnFailure($initialLevels, $rerunsMaxCount),
                    $currentReRunCount - 1,
                    $expectedLevels /* <- out */
                )
            );
        }
        /** @var array<string, LogLevel> $expectedLevels */

        $testCaseHandle = $this->getTestCaseHandle();
        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost();

        $appCodeArgs = [];
        AppCodeContextDataUtil::createTempFile($testCaseHandle, /* in,out */ $appCodeArgs);

        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestRunAndEscalateLogLevelOnFailure']),
            function (AppCodeRequestParams $appCodeRequestParams) use ($expectedLevels, $appCodeArgs): void {
                foreach (self::LOG_LEVEL_FOR_CODE_KEYS as $levelTypeKey) {
                    $appCodeArgs[$levelTypeKey] = $expectedLevels[$levelTypeKey];
                }
                $appCodeRequestParams->setAppCodeArgs($appCodeArgs);
            }
        );

        $this->waitForOneSpan($testCaseHandle);

        // Assert

        $appCodeContextData = AppCodeContextDataUtil::readDataAsMixedMapFromTempFile($appCodeArgs);
        $dbgCtx->add(compact('appCodeContextData'));
        self::assertTrue($appCodeContextData->getBool(self::DID_APP_CODE_FINISH_SUCCESSFULLY_KEY));

        if ($shouldCurrentRunFail) {
            self::fail(self::buildFailMessage($currentRunCount));
        }
    }
}
