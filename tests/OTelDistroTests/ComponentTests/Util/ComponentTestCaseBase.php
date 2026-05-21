<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\ComponentTests\Util\OtlpData\Span;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\ClassNameUtil;
use OTelDistroTests\Util\Config\CompositeRawSnapshotSource;
use OTelDistroTests\Util\Config\ConfigSnapshotForProd;
use OTelDistroTests\Util\Config\EnvVarsRawSnapshotSource;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\Config\OptionsForProdMetadata;
use OTelDistroTests\Util\Config\Parser as ConfigParser;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\DebugContextScopeRef;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\Log\LogLevelUtil;
use OTelDistroTests\Util\MixedMap;
use OTelDistroTests\Util\RangeUtil;
use OTelDistroTests\Util\TestCaseBase;
use Override;
use PHPUnit\Framework\Assert;
use Throwable;

class ComponentTestCaseBase extends TestCaseBase
{
    public const LOG_LEVEL_FOR_PROD_CODE_KEY = 'log_level_for_prod_code';
    public const LOG_LEVEL_FOR_TEST_CODE_KEY = 'log_level_for_test_code';
    protected const LOG_LEVEL_FOR_CODE_KEYS = [self::LOG_LEVEL_FOR_PROD_CODE_KEY, self::LOG_LEVEL_FOR_TEST_CODE_KEY];

    protected const SHOULD_APP_CODE_CREATE_DUMMY_SPAN_KEY = 'should_app_code_create_dummy_span';
    protected const APP_CODE_DUMMY_SPAN_NAME = 'app_code_dummy_span_name';

    protected const SUB_APP_CODE_TO_CALL_KEY = 'app_sub_code_to_call';
    protected const DID_APP_CODE_FINISH_SUCCESSFULLY_KEY = 'is_app_code_finished_successfully';
    protected const THROWABLE_FROM_APP_CODE_KEY = 'throwable_from_app_code';

    private ?TestCaseHandle $testCaseHandle = null;

    protected function initTestCaseHandle(?LogLevel $escalatedLogLevelForProdCode = null): TestCaseHandle
    {
        if ($this->testCaseHandle !== null) {
            return $this->testCaseHandle;
        }
        $this->testCaseHandle = new TestCaseHandle($escalatedLogLevelForProdCode);
        return $this->testCaseHandle;
    }

    protected function tearDownTestCaseHandle(): void
    {
        if ($this->testCaseHandle !== null) {
            $this->testCaseHandle->tearDown();
            $this->testCaseHandle = null;
        }
    }

    protected function getTestCaseHandle(): TestCaseHandle
    {
        return $this->initTestCaseHandle();
    }

    #[Override]
    public function tearDown(): void
    {
        $this->tearDownTestCaseHandle();

        parent::tearDown();
    }

    public static function appCodeEmpty(): void
    {
    }

    /**
     * @noinspection PhpDocMissingThrowsInspection
     */
    public static function appCodeSetsHowFinished(MixedMap $appCodeRequestArgs): void
    {
        $logger = self::getLoggerStatic(__NAMESPACE__, __CLASS__, __FILE__);
        $logDebug = $logger->logDebug(__FUNCTION__);
        $logger->addAllContext(compact('appCodeRequestArgs'));

        $subAppCode = $appCodeRequestArgs->tryGetArray(self::SUB_APP_CODE_TO_CALL_KEY);
        $appCodeAuxOutput = [];
        try {
            if ($subAppCode !== null) {
                self::assertIsCallable($subAppCode);
                $logDebug?->with(__LINE__, 'Calling $subAppCode() ...', compact('subAppCode', 'appCodeRequestArgs'));
                $appSubCodeContextData = $subAppCode($appCodeRequestArgs);
                if ($appSubCodeContextData !== null) {
                    self::assertIsArray($appSubCodeContextData);
                    /** @var array<string, mixed> $appSubCodeContextData */
                    ArrayUtilForTests::append($appSubCodeContextData, /* in,out */ $appCodeAuxOutput);
                }
            }
            $logDebug?->with(__LINE__, 'Call to $appCodeImpl() finished successfully');
        } catch (Throwable $throwable) {
            $logDebug?->withThrowable(__LINE__, 'Call to $appCodeImpl() thrown', $throwable);
            ArrayUtilForTests::addAssertingKeyNew(self::DID_APP_CODE_FINISH_SUCCESSFULLY_KEY, false, /* in,out */ $appCodeAuxOutput);
            ArrayUtilForTests::addAssertingKeyNew(self::THROWABLE_FROM_APP_CODE_KEY, LoggableToString::convert($throwable), /* in,out */ $appCodeAuxOutput);
            AppCodeAuxOutputUtil::writeDataToTempFile($appCodeAuxOutput, $appCodeRequestArgs);
            throw $throwable;
        }
        ArrayUtilForTests::addAssertingKeyNew(self::DID_APP_CODE_FINISH_SUCCESSFULLY_KEY, true, /* in,out */ $appCodeAuxOutput);
        AppCodeAuxOutputUtil::writeDataToTempFile($appCodeAuxOutput, $appCodeRequestArgs);
    }

    /**
     * @phpstan-param ?callable(MixedMap): (void|array<string, mixed>) $subAppCode
     * @phpstan-param ?positive-int $expectedMinSpanCount
     * @phpstan-param ?callable(DebugContextScopeRef $dbgCtx, AgentBackendComms $agentBackendComms, MixedMap $appCodeAuxOutput): void $additionalAssertCode
     */
    protected function implTestForAppCodeSetsHowFinished(MixedMap $testArgs, ?callable $subAppCode = null, ?int $expectedMinSpanCount = null, ?callable $additionalAssertCode = null): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $testCaseHandle = $this->getTestCaseHandle();

        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams) use ($testArgs): void {
                self::ensureTransactionSpanEnabled($appCodeHostParams);
                self::disableTimingDependentFeatures($appCodeHostParams);
                self::copyProdOptionsToAppCodeHostParams($testArgs, $appCodeHostParams);
            }
        );

        /** @var array<string, mixed> $appCodeRequestArgs */
        $appCodeRequestArgs = $testArgs->cloneAsArray();
        AppCodeAuxOutputUtil::createTempFile(__CLASS__, $testCaseHandle, /* in,out */ $appCodeRequestArgs);

        ArrayUtilForTests::addAssertingKeyNew(self::SUB_APP_CODE_TO_CALL_KEY, $subAppCode, /* in,out */ $appCodeRequestArgs);
        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeSetsHowFinished']),
            function (AppCodeRequestParams $appCodeRequestParams) use ($appCodeRequestArgs): void {
                $appCodeRequestParams->setAppCodeRequestArgs($appCodeRequestArgs);
            }
        );

        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans(min: $expectedMinSpanCount ?? 1)); // 1 span (the root span) is the default
        $dbgCtx->add(compact('agentBackendComms'));

        // Assert

        $appCodeAuxOutput = AppCodeAuxOutputUtil::readDataAsMixedMapFromTempFile($appCodeRequestArgs);
        $dbgCtx->add(compact('appCodeAuxOutput'));
        self::assertTrue($appCodeAuxOutput->getBool(self::DID_APP_CODE_FINISH_SUCCESSFULLY_KEY));

        if ($additionalAssertCode !== null) {
            $additionalAssertCode($dbgCtx, $agentBackendComms, $appCodeAuxOutput);
        }
    }

    public static function appCodeCreatesDummySpan(MixedMap $appCodeRequestArgs): void
    {
        if ($appCodeRequestArgs->tryToGetBool(self::SHOULD_APP_CODE_CREATE_DUMMY_SPAN_KEY) ?? true) {
            OTelUtilForTests::startEndSpan(self::APP_CODE_DUMMY_SPAN_NAME);
        }
    }

    protected static function buildResourcesCleanerClientForAppCode(): ResourcesCleanerClient
    {
        $resCleanerId = AmbientContextForTests::testConfig()->dataPerProcess()->resourcesCleanerSpawnedProcessInternalId;
        Assert::assertNotNull($resCleanerId);
        $resCleanerPort = AmbientContextForTests::testConfig()->dataPerProcess()->resourcesCleanerPort;
        Assert::assertNotNull($resCleanerPort);
        return new ResourcesCleanerClient($resCleanerId, $resCleanerPort);
    }

    public static function isMainAppCodeHostHttp(): bool
    {
        return AmbientContextForTests::testConfig()->appCodeHostKind()->isHttp();
    }

    protected function skipIfMainAppCodeHostIsNotCliScript(): bool
    {
        if (self::isMainAppCodeHostHttp()) {
            self::dummyAssert();
            return true;
        }

        return false;
    }

    protected function skipIfMainAppCodeHostIsNotHttp(): bool
    {
        if (!self::isMainAppCodeHostHttp()) {
            self::dummyAssert();
            return true;
        }

        return false;
    }

    protected static function waitForOneSpan(TestCaseHandle $testCaseHandle): Span
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans(1));
        $dbgCtx->add(compact('agentBackendComms'));
        return $agentBackendComms->singleSpan();
    }

    /**
     * @param callable(): void $testCall
     *
     * @noinspection PhpDocMissingThrowsInspection
     */
    protected function runAndEscalateLogLevelOnFailure(string $dbgTestDesc, callable $testCall): void
    {
        $logLevelForTestCodeToRestore = AmbientContextForTests::testConfig()->logLevel;
        try {
            $this->runAndEscalateLogLevelOnFailureImpl($dbgTestDesc, $testCall);
        } finally {
            AmbientContextForTests::resetLogLevel($logLevelForTestCodeToRestore);
        }
    }

    /**
     * @param callable(): void $testCall
     *
     * @noinspection PhpDocMissingThrowsInspection
     */
    private function runAndEscalateLogLevelOnFailureImpl(string $dbgTestDesc, callable $testCall): void
    {
        try {
            $testCall();
            return;
        } catch (Throwable $ex) {
            $initiallyFailedTestException = $ex;
        }

        $logger = self::getLoggerStatic(__NAMESPACE__, __CLASS__, __FILE__)->addAllContext(compact('dbgTestDesc', 'initiallyFailedTestException'));
        $logOutsideIt = $logger->logCritical(__FUNCTION__);

        $logOutsideIt?->with(__LINE__, 'Test case code exited by exception');

        if ($this->testCaseHandle === null) {
            $logOutsideIt?->with(__LINE__, 'Test failed but $this->testCaseHandle is null - NOT re-running the test with escalated log levels');
            throw $initiallyFailedTestException;
        }
        $escalatedProdLogLevelOptName = AmbientContextForTests::testConfig()->escalatedRerunsProdCodeLogLevelOptionName();
        $logger->addAllContext(compact('escalatedProdLogLevelOptName'));
        $initiallyFailedTestLogLevels = $this->getCurrentLogLevels($this->testCaseHandle, $escalatedProdLogLevelOptName);
        if (ArrayUtilForTests::isEmpty($initiallyFailedTestLogLevels)) {
            $logOutsideIt?->with(__LINE__, 'Test failed but not even one app code host has started successfully - NOT re-running the test with escalated log levels');
            throw $initiallyFailedTestException;
        }
        $logger->addAllContext(compact('initiallyFailedTestLogLevels'));
        $logOutsideIt?->with(__LINE__, 'Test failed');

        $escalatedLogLevelsSeq = self::generateLevelsForRunAndEscalateLogLevelOnFailure($initiallyFailedTestLogLevels, AmbientContextForTests::testConfig()->escalatedRerunsMaxCount);
        $rerunCount = 0;
        foreach ($escalatedLogLevelsSeq as $escalatedLogLevels) {
            $this->tearDownTestCaseHandle();

            ++$rerunCount;
            $loggerPerIt = $logger->inherit()->addAllContext(compact('rerunCount', 'escalatedLogLevels'));
            $logPerIt = $loggerPerIt->logCritical(__FUNCTION__);

            $logPerIt?->with(__LINE__, 'Re-running failed test with escalated log levels...');

            AmbientContextForTests::resetLogLevel($escalatedLogLevels[self::LOG_LEVEL_FOR_TEST_CODE_KEY]);
            $this->initTestCaseHandle($escalatedLogLevels[self::LOG_LEVEL_FOR_PROD_CODE_KEY]);

            try {
                $testCall();
                $logPerIt?->with(__LINE__, 'Re-run of failed test with escalated log levels did NOT fail (which is bad :(');
            } catch (Throwable $ex) {
                $logPerIt?->with(__LINE__, 'Re-run of failed test with escalated log levels failed (which is good :)', compact('ex'));
                throw $ex;
            }
        }

        if ($rerunCount === 0) {
            $logOutsideIt?->with(__LINE__, 'There were no test re-runs with escalated log levels - re-throwing original test failure exception');
        } else {
            $logOutsideIt?->with(__LINE__, 'All test re-runs with escalated log levels did NOT fail (which is bad :( - re-throwing original test failure exception');
        }
        throw $initiallyFailedTestException;
    }

    /**
     * @param class-string $testClass
     */
    protected static function buildDbgDescForTest(string $testClass, string $testFunc): string
    {
        return ClassNameUtil::fqToShort($testClass) . '::' . $testFunc;
    }

    /**
     * @param class-string $testClass
     */
    protected static function buildDbgDescForTestWithArgs(string $testClass, string $testFunc, MixedMap $testArgs): string
    {
        return ClassNameUtil::fqToShort($testClass) . '::' . $testFunc . '(' . LoggableToString::convert($testArgs) . ')';
    }

    /**
     * @return array<string, LogLevel>
     */
    private function getCurrentLogLevels(TestCaseHandle $testCaseHandle, OptionForProdName $logLevelOptName): array
    {
        /** @var array<string, LogLevel> $result */
        $result = [];
        $prodCodeLogLevels = $testCaseHandle->getProdCodeLogLevels($logLevelOptName);
        if (ArrayUtilForTests::isEmpty($prodCodeLogLevels)) {
            return [];
        }
        /** @var non-empty-list<LogLevel> $prodCodeLogLevels */
        $result[self::LOG_LEVEL_FOR_PROD_CODE_KEY] = LogLevel::from(min(array_map(fn(LogLevel $logLevel) => $logLevel->value, $prodCodeLogLevels)));
        $result[self::LOG_LEVEL_FOR_TEST_CODE_KEY] = AmbientContextForTests::testConfig()->logLevel;
        return $result;
    }

    /**
     * @param array<string, LogLevel> $initialLevels
     *
     * @return iterable<array<string, LogLevel>>
     */
    public static function generateEscalatedLogLevels(array $initialLevels): iterable
    {
        Assert::assertNotEmpty($initialLevels);

        /**
         * @param array<string, LogLevel> $currentLevels
         */
        $haveCurrentLevelsReachedInitial = function (array $currentLevels) use ($initialLevels): bool {
            foreach ($initialLevels as $levelTypeKey => $initialLevel) {
                /** @var LogLevel $initialLevel */
                /** @var array<string, LogLevel> $currentLevels */
                if ($initialLevel->value < $currentLevels[$levelTypeKey]->value) {
                    return false;
                }
            }
            return true;
        };

        /** @var int $minInitialLevel */
        $minInitialLevel = min(array_map(fn(LogLevel $logLevel) => $logLevel->value, $initialLevels));
        $maxDelta = 0;
        foreach ($initialLevels as $initialLevel) {
            $maxDelta = max($maxDelta, LogLevelUtil::getHighest()->value - $initialLevel->value);
        }
        foreach (RangeUtil::generateDown(LogLevelUtil::getHighest()->value, $minInitialLevel) as $baseLevelAsInt) {
            Assert::assertGreaterThan(LogLevel::off->value, $baseLevelAsInt);
            /** @var array<string, LogLevel> $currentLevels */
            $currentLevels = [];
            foreach (self::LOG_LEVEL_FOR_CODE_KEYS as $levelTypeKey) {
                $currentLevels[$levelTypeKey] = LogLevel::from($baseLevelAsInt);
            }
            yield $currentLevels;

            foreach (RangeUtil::generate(1, $maxDelta + 1) as $delta) {
                foreach (self::LOG_LEVEL_FOR_CODE_KEYS as $levelTypeKey) {
                    if ($baseLevelAsInt < $initialLevels[$levelTypeKey]->value + $delta) {
                        continue;
                    }
                    $currentLevels[$levelTypeKey] = LogLevel::from($baseLevelAsInt - $delta);
                    if (!$haveCurrentLevelsReachedInitial($currentLevels)) {
                        yield $currentLevels;
                    }
                    $currentLevels[$levelTypeKey] = LogLevel::from($baseLevelAsInt);
                }
            }
        }
    }

    /**
     * @param array<string, LogLevel> $initialLevels
     *
     * @return iterable<array<string, LogLevel>>
     */
    protected static function generateLevelsForRunAndEscalateLogLevelOnFailure(array $initialLevels, int $eachLevelsSetMaxCount): iterable
    {
        $result = self::generateEscalatedLogLevels($initialLevels);
        $result = IterableUtil::concat($result, [$initialLevels]);
        /** @noinspection PhpUnnecessaryLocalVariableInspection */
        $result = IterableUtil::duplicateEachElement($result, $eachLevelsSetMaxCount);
        return $result;
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, TValue> $array
     * @param TKey                $key
     *
     * @return TValue
     */
    public static function &assertAndGetFromArrayByKey(array $array, int|string $key)
    {
        Assert::assertArrayHasKey($key, $array);
        return $array[$key];
    }

    protected static function buildProdConfigInAppCodeContext(): ConfigSnapshotForProd
    {
        /** @var ?array<string, string[]> $envVarPrefixToOptNames */
        static $envVarPrefixToOptNames = null;

        $allOptsMeta = OptionsForProdMetadata::get();

        if ($envVarPrefixToOptNames === null) {
            $envVarPrefixToOptNames = [];
            foreach (OptionForProdName::cases() as $optName) {
                $envVarNamePrefix = $optName->getEnvVarNamePrefix();
                if (!array_key_exists($envVarNamePrefix, $envVarPrefixToOptNames)) {
                    $envVarPrefixToOptNames[$envVarNamePrefix] = [];
                }
                $envVarPrefixToOptNames[$envVarNamePrefix][] = $optName->name;
            }
        }

        $envVarsSnapSources = [];
        foreach ($envVarPrefixToOptNames as $currentEnvVarsPrefix => $optNames) {
            $envVarsSnapSources[] = new EnvVarsRawSnapshotSource($currentEnvVarsPrefix, $optNames);
        }
        $rawSnapshotSource = new CompositeRawSnapshotSource($envVarsSnapSources);

        $parser = new ConfigParser(AmbientContextForTests::loggerFactory());
        $rawSnapshot = $rawSnapshotSource->currentSnapshot($allOptsMeta);
        return new ConfigSnapshotForProd($parser->parse($allOptsMeta, $rawSnapshot));
    }

    protected static function getExpectedTransactionSpanName(): string
    {
        return self::isMainAppCodeHostHttp()
            ? HttpAppCodeRequestParams::DEFAULT_HTTP_REQUEST_METHOD . ' ' . HttpAppCodeRequestParams::DEFAULT_HTTP_REQUEST_URL_PATH
            : CliScriptAppCodeHostHandle::getRunScriptNameFullPath();
    }

    protected static function disableTimingDependentFeatures(AppCodeHostParams $appCodeHostParams): void
    {
        $appCodeHostParams->setProdOption(OptionForProdName::inferred_spans_enabled, false);
    }

    protected static function ensureTransactionSpanEnabled(AppCodeHostParams $appCodeHostParams): void
    {
        $appCodeHostParams->setProdOption(OptionForProdName::transaction_span_enabled, true);
        $appCodeHostParams->setProdOption(OptionForProdName::transaction_span_enabled_cli, true);
    }

    protected static function copyProdOptionsToAppCodeHostParams(MixedMap $testArgs, AppCodeHostParams $appCodeHostParams): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);
        $dbgCtx->pushSubScope();
        foreach ($testArgs as $testArgKey => $testArgVal) {
            if (array_key_exists($testArgKey, OptionsForProdMetadata::get())) {
                $dbgCtx->resetTopSubScope(compact('testArgKey', 'testArgVal'));
                $appCodeHostParams->setProdOptionIfNotDefault(
                    AssertEx::notNull(OptionForProdName::tryToFindByName($testArgKey)),
                    $testArgVal === null ? null : AppCodeHostParams::assertValidProdOptionValueType($testArgVal, $testArgKey),
                );
            }
        }
        $dbgCtx->popSubScope();
    }
}
