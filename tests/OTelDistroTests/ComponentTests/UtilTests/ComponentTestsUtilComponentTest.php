<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\UtilTests;

use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\ComponentTests\Util\AppCodeAuxOutputUtil;
use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\EnvVarUtilForTests;
use OTelDistroTests\ComponentTests\Util\WaitForOTelSignalCounts;
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

    public static function appCodeForTestRunAndEscalateLogLevelOnFailure(MixedMap $appCodeRequestArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);
        $dbgCtx->add(compact('appCodeRequestArgs'));
        $dbgCtx->add(['testConfig' => AmbientContextForTests::testConfig()]);
        $expectedLogLevelForProdCode = $appCodeRequestArgs->getLogLevel(self::LOG_LEVEL_FOR_PROD_CODE_KEY);
        $dbgCtx->add(compact('expectedLogLevelForProdCode'));
        $prodConfig = self::buildProdConfigInAppCodeContext();
        $dbgCtx->add(compact('prodConfig'));
        $actualLogLevelForProdCode = $prodConfig->getOptionValueByName(AmbientContextForTests::testConfig()->escalatedRerunsProdCodeLogLevelOptionName());
        $dbgCtx->add(compact('actualLogLevelForProdCode'));
        self::assertSame($expectedLogLevelForProdCode, $actualLogLevelForProdCode);
        $expectedLogLevelForTestCode = $appCodeRequestArgs->getLogLevel(self::LOG_LEVEL_FOR_TEST_CODE_KEY);
        $dbgCtx->add(compact('expectedLogLevelForTestCode'));
        $actualLogLevelForTestCode = AmbientContextForTests::testConfig()->logLevel;
        $dbgCtx->add(compact('actualLogLevelForTestCode'));
        self::assertSame($expectedLogLevelForTestCode, $actualLogLevelForTestCode);
    }

    public function test0WithoutEscalation(): void
    {
        /**
         * This test case cannot be refactored to use ComponentTestCaseBase::implTestForAppCodeSetsHowFinished
         * because it needs for the main app code host to be created for $testCaseHandle->getProdCodeLogLevels
         * to work correctly
         */

        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $testCaseHandle = $this->getTestCaseHandle();

        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams): void {
                self::ensureTransactionSpanEnabled($appCodeHostParams);
            }
        );

        /** @var array<string, mixed> $appCodeRequestArgs */
        $appCodeRequestArgs = [
            self::LOG_LEVEL_FOR_PROD_CODE_KEY =>
                ArrayUtilForTests::getSingleValue($testCaseHandle->getProdCodeLogLevels(AmbientContextForTests::testConfig()->escalatedRerunsProdCodeLogLevelOptionName())),
            self::LOG_LEVEL_FOR_TEST_CODE_KEY => AmbientContextForTests::testConfig()->logLevel,
        ];
        AppCodeAuxOutputUtil::createTempFile(__CLASS__, $testCaseHandle, /* in,out */ $appCodeRequestArgs);

        ArrayUtilForTests::addAssertingKeyNew(self::SUB_APP_CODE_TO_CALL_KEY, [__CLASS__, 'appCodeForTestRunAndEscalateLogLevelOnFailure'], /* in,out */ $appCodeRequestArgs);
        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeSetsHowFinished']),
            function (AppCodeRequestParams $appCodeRequestParams) use ($appCodeRequestArgs): void {
                $appCodeRequestParams->setAppCodeRequestArgs($appCodeRequestArgs);
            }
        );

        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans(1)); // exactly 1 span (the root span) is expected
        $dbgCtx->add(compact('agentBackendComms'));

        // Assert

        $appCodeAuxOutput = AppCodeAuxOutputUtil::readDataAsMixedMapFromTempFile($appCodeRequestArgs);
        $dbgCtx->add(compact('appCodeAuxOutput'));
        self::assertTrue($appCodeAuxOutput->getBool(self::DID_APP_CODE_FINISH_SUCCESSFULLY_KEY));
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

        $appCodeRequestArgs = [];
        AppCodeAuxOutputUtil::createTempFile(__CLASS__, $testCaseHandle, /* in,out */ $appCodeRequestArgs);

        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestRunAndEscalateLogLevelOnFailure']),
            function (AppCodeRequestParams $appCodeRequestParams) use ($expectedLevels, $appCodeRequestArgs): void {
                foreach (self::LOG_LEVEL_FOR_CODE_KEYS as $levelTypeKey) {
                    $appCodeRequestArgs[$levelTypeKey] = $expectedLevels[$levelTypeKey];
                }
                $appCodeRequestParams->setAppCodeRequestArgs($appCodeRequestArgs);
            }
        );

        $this->waitForOneSpan($testCaseHandle);

        // Assert

        $appCodeAuxOutput = AppCodeAuxOutputUtil::readDataAsMixedMapFromTempFile($appCodeRequestArgs);
        $dbgCtx->add(compact('appCodeAuxOutput'));
        self::assertTrue($appCodeAuxOutput->getBool(self::DID_APP_CODE_FINISH_SUCCESSFULLY_KEY));

        if ($shouldCurrentRunFail) {
            self::fail(self::buildFailMessage($currentRunCount));
        }
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
            $this->runAndEscalateLogLevelOnFailure(
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
    }
}
