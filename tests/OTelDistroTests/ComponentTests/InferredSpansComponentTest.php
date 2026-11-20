<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\WithSpan;
use OpenTelemetry\API\Trace\Span as ApiSpan;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Distro\Util\ArrayUtil;
use OTelDistroTests\ComponentTests\Util\AppCodeAuxOutputUtil;
use OTelDistroTests\ComponentTests\Util\AppCodeContextUtil;
use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\InferredSpanExpectationsBuilder;
use OTelDistroTests\ComponentTests\Util\SpanSequenceExpectations;
use OTelDistroTests\ComponentTests\Util\StackTraceExpectations;
use OTelDistroTests\ComponentTests\Util\WaitForOTelSignalCounts;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\DataProviderForTestBuilder;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\MixedMap;
use OTelDistroTests\Util\StackTraceUtil;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;

use function debug_backtrace;

/**
 * @group smoke
 * @group does_not_require_external_services
 *
 * @phpstan-import-type DebugBacktraceResult from StackTraceUtil
 * @phpstan-type FuncName string
 * @phpstan-type ExpectedHelperDataForFunc array{'stack_trace': DebugBacktraceResult, 'line_number'?: int}
 * @phpstan-type ExpectedHelperData array<FuncName, ExpectedHelperDataForFunc>
 */
final class InferredSpansComponentTest extends ComponentTestCaseBase
{
    private const IS_INFERRED_SPANS_ENABLED_KEY = 'is_inferred_spans_enabled';
    private const CAPTURE_SLEEPS_KEY = 'capture_sleeps';

    private const SLEEP_DURATION_SECONDS = 5;
    private const INFERRED_MIN_DURATION_SECONDS_TO_CAPTURE_SLEEPS = self::SLEEP_DURATION_SECONDS - 2;
    private const INFERRED_MIN_DURATION_SECONDS_TO_OMIT_SLEEPS = self::SLEEP_DURATION_SECONDS * 3 - 1;

    private const SLEEP_FUNC_NAME = 'sleep';
    private const MULTI_STEP_USLEEP_FUNC_NAME = 'multiStepUsleep';
    private const TIME_NANOSLEEP_FUNC_NAME = 'time_nanosleep';
    private const SLEEP_FUNC_NAMES = [self::SLEEP_FUNC_NAME, self::MULTI_STEP_USLEEP_FUNC_NAME, self::TIME_NANOSLEEP_FUNC_NAME];

    private const EXPECTED_HELPER_DATA_KEY = 'expected_helper_data';
    private const STACK_TRACE_KEY = 'stack_trace';
    private const LINE_NUMBER_KEY = 'line_number';

    private const INSTRUMENTED_SPAN_NAME = 'instrumented work';
    private const WITH_SPAN_NAME = 'with span work';
    private const INTERNAL_FUNC_SPAN_NAME = 'instrumented internal function';
    private const CALLBACK_INTERNAL_FUNC_SPAN_NAME = 'instrumented callback-driven internal function';

    private const INSTRUMENTED_WORK_SECONDS = 1;

    private static bool $armedForCallbackDrivenInternalFunction = false;
    private static bool $startedSpanForCallbackDrivenInternalFunction = false;

    /**
     * @return iterable<array{MixedMap}>
     */
    public static function dataProviderForTestInferredSpans(): iterable
    {
        $result = (new DataProviderForTestBuilder())
            ->addBoolKeyedDimensionOnlyFirstValueCombinable(self::IS_INFERRED_SPANS_ENABLED_KEY)
            ->addBoolKeyedDimensionOnlyFirstValueCombinable(self::CAPTURE_SLEEPS_KEY)
            ->build();

        return self::adaptToSmoke(DataProviderForTestBuilder::convertEachDataSetToMixedMap($result));
    }

    /** @noinspection PhpSameParameterValueInspection */
    private static function multiStepUsleep(int $secondsToSleep): void
    {
        $microsecondsInSecond = 1000 * 1000;
        $microsecondsInEachSleep = $microsecondsInSecond / 5;
        $numberOfSleeps = intval(($secondsToSleep * $microsecondsInSecond) / $microsecondsInEachSleep);
        $lastSleep = $secondsToSleep % $microsecondsInEachSleep;
        for ($i = 0; $i < $numberOfSleeps; ++$i) {
            usleep($microsecondsInEachSleep);
        }
        usleep($lastSleep);
    }

    /**
     * @phpstan-param ExpectedHelperData $expectedHelperData
     */
    private static function mySleep(string $sleepFuncToUse, /* ref */ array &$expectedHelperData): void
    {
        switch ($sleepFuncToUse) {
            case self::SLEEP_FUNC_NAME:
                self::assertSame(0, sleep(self::SLEEP_DURATION_SECONDS));
                $sleepCallLine = __LINE__ - 1;
                break;
            case self::MULTI_STEP_USLEEP_FUNC_NAME:
                self::multiStepUsleep(self::SLEEP_DURATION_SECONDS);
                $sleepCallLine = __LINE__ - 1;
                break;
            case self::TIME_NANOSLEEP_FUNC_NAME:
                self::assertTrue(time_nanosleep(self::SLEEP_DURATION_SECONDS, nanoseconds: 0));
                $sleepCallLine = __LINE__ - 1;
                break;
            default:
                self::fail('Unknown sleepFuncToUse: `' . $sleepFuncToUse . '\'');
        }

        $expectedStackTrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $expectedHelperData[$sleepFuncToUse] = [self::STACK_TRACE_KEY => $expectedStackTrace, self::LINE_NUMBER_KEY => $sleepCallLine];
    }

    public static function appCodeForTestInferredSpans(MixedMap $appCodeRequestArgs): void
    {
        /** @var ExpectedHelperData $expectedHelperData */
        $expectedHelperData = [];

        self::mySleep(self::SLEEP_FUNC_NAME, /* ref */ $expectedHelperData);
        self::mySleep(self::MULTI_STEP_USLEEP_FUNC_NAME, /* ref */ $expectedHelperData);
        self::mySleep(self::TIME_NANOSLEEP_FUNC_NAME, /* ref */ $expectedHelperData);

        // Slice 1 frame for this function call since this function call is converted to an inferred span
        // and properties from the stack frame converted to an inferred span go to CODE_FILE_PATH and CODE_LINE_NUMBER attributes.
        // This method is a special case since it's called by call_user_func, so there should not be CODE_FILE_PATH and CODE_LINE_NUMBER attributes.
        $expectedHelperData[__FUNCTION__] = [self::STACK_TRACE_KEY => array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), offset: 1)];
        AppCodeAuxOutputUtil::writeDataToTempFile([self::EXPECTED_HELPER_DATA_KEY => $expectedHelperData], $appCodeRequestArgs);
    }

    private function implTestInferredSpans(MixedMap $testArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $isInferredSpansEnabled = $testArgs->getBool(self::IS_INFERRED_SPANS_ENABLED_KEY);
        $shouldCaptureSleeps = $testArgs->getBool(self::CAPTURE_SLEEPS_KEY);

        $appCodeTarget = AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestInferredSpans']);
        $appCodeMethodName = $appCodeTarget->appCodeMethod;
        self::assertIsString($appCodeMethodName);

        $testCaseHandle = $this->getTestCaseHandle();

        /** @var array<string, mixed> $appCodeRequestArgs */
        $appCodeRequestArgs = [];
        AppCodeAuxOutputUtil::createTempFile(__CLASS__, $testCaseHandle, /* in,out */ $appCodeRequestArgs);

        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams) use ($isInferredSpansEnabled, $shouldCaptureSleeps): void {
                $appCodeHostParams->setProdOption(OptionForProdName::inferred_spans_enabled, $isInferredSpansEnabled);
                $inferredMinDuration = $shouldCaptureSleeps ? self::INFERRED_MIN_DURATION_SECONDS_TO_CAPTURE_SLEEPS : self::INFERRED_MIN_DURATION_SECONDS_TO_OMIT_SLEEPS;
                $appCodeHostParams->setProdOption(OptionForProdName::inferred_spans_min_duration, $inferredMinDuration . 's');
            }
        );
        $appCodeHost->execAppCode(
            $appCodeTarget,
            function (AppCodeRequestParams $appCodeRequestParams) use ($appCodeRequestArgs): void {
                $appCodeRequestParams->setAppCodeRequestArgs($appCodeRequestArgs);
            }
        );

        // Inferred spans count is at least 4: 3 sleep spans + 1 span for appCode method
        $expectedInferredSpansMinCount = $isInferredSpansEnabled ? (($shouldCaptureSleeps ? 3 : 0) + 1) : 0;
        // Regular (i.e., not inferred) spans count is 1 - the automatic root span
        $expectedSpanMinCount = $expectedInferredSpansMinCount + 1;
        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(
            $isInferredSpansEnabled ? WaitForOTelSignalCounts::spansAtLeast($expectedSpanMinCount) : WaitForOTelSignalCounts::spans($expectedSpanMinCount)
        );
        $dbgCtx->add(compact('agentBackendComms'));

        $expectedHelperData = AppCodeAuxOutputUtil::readDataAsMixedMapFromTempFile($appCodeRequestArgs)->getArray(self::EXPECTED_HELPER_DATA_KEY);
        /** @var ExpectedHelperData $expectedHelperData */
        $dbgCtx->add(compact('expectedHelperData'));

        $rootSpan = IterableUtil::singleValue($agentBackendComms->findRootSpans());
        foreach ($agentBackendComms->spans() as $span) {
            self::assertSame($rootSpan->traceId, $span->traceId);
        }

        if (!$isInferredSpansEnabled) {
            return;
        }

        $stackTraceExpectations = StackTraceExpectations::fromDebugBacktrace($expectedHelperData[$appCodeMethodName][self::STACK_TRACE_KEY]);
        $appCodeSpanExpectations = (new InferredSpanExpectationsBuilder())
            ->addNotAllowedAttribute(CodeAttributes::CODE_FILE_PATH) // appCodeForTestInferredSpans method is called by call_user_func so there is no CODE_FILE_PATH
            ->addNotAllowedAttribute(CodeAttributes::CODE_LINE_NUMBER) // appCodeForTestInferredSpans method is called by call_user_func so there is no CODE_LINE_NUMBER
            ->buildForStaticMethod(__CLASS__, $appCodeMethodName, $stackTraceExpectations);
        $appCodeSpan = $agentBackendComms->singleSpanByName($appCodeSpanExpectations->name->expectedValue->getValue());
        self::assertTrue($agentBackendComms->isSpanDescendantOf($appCodeSpan, $rootSpan));
        $appCodeSpanExpectations->assertMatches($appCodeSpan);

        if (!$shouldCaptureSleeps) {
            return;
        }

        $sleepSpansExpectationsBuilder = (new InferredSpanExpectationsBuilder())->addAttribute(CodeAttributes::CODE_FILE_PATH, __FILE__);
        $expectedSleepSpans = [];
        $actualSleepSpans = [];
        foreach (self::SLEEP_FUNC_NAMES as $sleepFunc) {
            $expectedCodeLineNumber = AssertEx::isPositiveInt(ArrayUtil::getValueIfKeyExistsElse(self::LINE_NUMBER_KEY, $expectedHelperData[$sleepFunc], null));
            $stackTraceExpectations = StackTraceExpectations::fromDebugBacktrace($expectedHelperData[$sleepFunc][self::STACK_TRACE_KEY]);
            $expectedSleepSpan = $sleepFunc === self::MULTI_STEP_USLEEP_FUNC_NAME
                ? $sleepSpansExpectationsBuilder->buildForStaticMethod(__CLASS__, $sleepFunc, $stackTraceExpectations, $expectedCodeLineNumber)
                : $sleepSpansExpectationsBuilder->buildForFunction($sleepFunc, $stackTraceExpectations, $expectedCodeLineNumber);
            $expectedSleepSpans[] = $expectedSleepSpan;
            $actualSleepSpan = $agentBackendComms->singleSpanByName($expectedSleepSpan->name->expectedValue->getValue());
            $actualSleepSpans[] = $actualSleepSpan;
            self::assertTrue($agentBackendComms->isSpanDescendantOf($actualSleepSpan, $appCodeSpan));
        }

        (new SpanSequenceExpectations($expectedSleepSpans))->assertMatches($actualSleepSpans);
    }

    private static function innerWork(): void
    {
        self::multiStepUsleep(self::INSTRUMENTED_WORK_SECONDS);
    }

    public static function instrumentedWork(): void
    {
        self::innerWork();
    }

    /**
     * Hooks a function the way auto-instrumentations do: the pre hook starts a span and attaches its scope,
     * and the post hook detaches whatever scope is current (Context::storage()->scope()) and ends the span in it.
     * Samples taken while the hooked function runs attach inferred spans on top of that scope.
     */
    public static function appCodeForTestInstrumentationSpanIsExportedWhenSampledInsideHookedFunction(): void
    {
        $hook = AppCodeContextUtil::adaptClassNameRawStringToScoping('OpenTelemetry\\Instrumentation\\hook');
        self::assertTrue(function_exists($hook));
        $globals = AppCodeContextUtil::adaptClassNameToScoping(Globals::class);
        $context = AppCodeContextUtil::adaptClassNameToScoping(Context::class);
        $apiSpan = AppCodeContextUtil::adaptClassNameToScoping(ApiSpan::class);

        $hookAdded = $hook(
            __CLASS__,
            'instrumentedWork',
            static function () use ($globals, $context): void {
                $span = $globals::tracerProvider()->getTracer('test')->spanBuilder(self::INSTRUMENTED_SPAN_NAME)->startSpan();
                $context::storage()->attach($span->storeInContext($context::getCurrent()));
            },
            static function () use ($context, $apiSpan): void {
                $scope = $context::storage()->scope();
                if ($scope === null) {
                    return;
                }
                $scope->detach();
                $apiSpan::fromContext($scope->context())->end();
            }
        );
        self::assertTrue($hookAdded);

        self::instrumentedWork();
    }

    private function implTestInstrumentationSpanIsExportedWhenSampledInsideHookedFunction(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $testCaseHandle = $this->getTestCaseHandle();
        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams): void {
                $appCodeHostParams->setProdOption(OptionForProdName::inferred_spans_enabled, true);
                $appCodeHostParams->setProdOption(OptionForProdName::inferred_spans_sampling_interval, '20ms');
            }
        );
        $appCodeHost->execAppCode(AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestInstrumentationSpanIsExportedWhenSampledInsideHookedFunction']));

        // root span + the instrumentation span + at least the inferred spans for the app code method, instrumentedWork and innerWork
        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spansAtLeast(5));
        $dbgCtx->add(compact('agentBackendComms'));

        $rootSpan = IterableUtil::singleValue($agentBackendComms->findRootSpans());
        // Unless the extension ends the inferred spans before the post hooks, this span is never ended, so it is not exported
        $agentBackendComms->singleSpanByName(self::INSTRUMENTED_SPAN_NAME);

        $spanIds = [];
        foreach ($agentBackendComms->spans() as $span) {
            self::assertSame($rootSpan->traceId, $span->traceId);
            $spanIds[$span->id] = true;
        }
        foreach ($agentBackendComms->spans() as $span) {
            $dbgCtx->add(compact('span'));
            if ($span->parentId !== null) {
                self::assertArrayHasKey($span->parentId, $spanIds, 'Parent of span ' . $span->name . ' was not exported');
            }
        }
    }

    public function testInstrumentationSpanIsExportedWhenSampledInsideHookedFunction(): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTest(__CLASS__, __FUNCTION__),
            fn() => $this->implTestInstrumentationSpanIsExportedWhenSampledInsideHookedFunction()
        );
    }

    /**
     * Same as appCodeForTestInstrumentationSpanIsExportedWhenSampledInsideHookedFunction but the hooked function is an internal one,
     * which does not go through the observer. The sample that follows the internal function call is taken right after it returns.
     */
    public static function appCodeForTestInternalFunctionHookWithScope(): void
    {
        $hook = AppCodeContextUtil::adaptClassNameRawStringToScoping('OpenTelemetry\\Instrumentation\\hook');
        self::assertTrue(function_exists($hook));
        $globals = AppCodeContextUtil::adaptClassNameToScoping(Globals::class);
        $context = AppCodeContextUtil::adaptClassNameToScoping(Context::class);
        $apiSpan = AppCodeContextUtil::adaptClassNameToScoping(ApiSpan::class);

        $hookAdded = $hook(
            null,
            'usleep',
            static function () use ($globals, $context): void {
                $span = $globals::tracerProvider()->getTracer('test')->spanBuilder(self::INTERNAL_FUNC_SPAN_NAME)->startSpan();
                $context::storage()->attach($span->storeInContext($context::getCurrent()));
            },
            static function () use ($context, $apiSpan): void {
                $scope = $context::storage()->scope();
                if ($scope === null) {
                    return;
                }
                $scope->detach();
                $apiSpan::fromContext($scope->context())->end();
            }
        );
        self::assertTrue($hookAdded);

        self::outerOfInternalFunctionWork();
    }

    private static function outerOfInternalFunctionWork(): void
    {
        usleep(self::INSTRUMENTED_WORK_SECONDS * 1_000_000);
    }

    public function testInternalFunctionHookWithScope(): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTest(__CLASS__, __FUNCTION__),
            function (): void {
                DebugContext::getCurrentScope(/* out */ $dbgCtx);

                $testCaseHandle = $this->getTestCaseHandle();
                $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
                    function (AppCodeHostParams $appCodeHostParams): void {
                        $appCodeHostParams->setProdOption(OptionForProdName::inferred_spans_enabled, true);
                        $appCodeHostParams->setProdOption(OptionForProdName::inferred_spans_sampling_interval, '20ms');
                    }
                );
                $appCodeHost->execAppCode(AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestInternalFunctionHookWithScope']));

                $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spansAtLeast(3));
                $dbgCtx->add(compact('agentBackendComms'));

                $rootSpan = IterableUtil::singleValue($agentBackendComms->findRootSpans());
                $agentBackendComms->singleSpanByName(self::INTERNAL_FUNC_SPAN_NAME);

                $spanIds = [];
                foreach ($agentBackendComms->spans() as $span) {
                    self::assertSame($rootSpan->traceId, $span->traceId);
                    $spanIds[$span->id] = true;
                }
                foreach ($agentBackendComms->spans() as $span) {
                    $dbgCtx->add(compact('span'));
                    if ($span->parentId !== null) {
                        self::assertArrayHasKey($span->parentId, $spanIds, 'Parent of span ' . $span->name . ' was not exported');
                    }
                }
            }
        );
    }

    /**
     * A hook with only a pre (or only a post) callback must not unbalance the enter/exit bookkeeping:
     * the pair hooked function that wraps it still has to get its own inferred spans ended.
     * The two kinds are tested separately, because a stale enter (pre only) and a missing enter (post only) cancel each other out.
     */
    private static function appCodeForPartialHookScenario(bool $preOnly, bool $postHookAddedLater = false): void
    {
        $hook = AppCodeContextUtil::adaptClassNameRawStringToScoping('OpenTelemetry\\Instrumentation\\hook');
        self::assertTrue(function_exists($hook));
        $globals = AppCodeContextUtil::adaptClassNameToScoping(Globals::class);
        $context = AppCodeContextUtil::adaptClassNameToScoping(Context::class);
        $apiSpan = AppCodeContextUtil::adaptClassNameToScoping(ApiSpan::class);

        if ($postHookAddedLater) {
            // the function is observed with a pre hook only, a post hook is added after that
            self::assertTrue($hook(__CLASS__, 'partiallyHookedWork', static function (): void {
            }, null));
            self::partiallyHookedWork();
            self::assertTrue($hook(__CLASS__, 'partiallyHookedWork', null, static function (): void {
            }));
        } elseif ($preOnly) {
            self::assertTrue($hook(__CLASS__, 'partiallyHookedWork', static function (): void {
            }, null));
        } else {
            self::assertTrue($hook(__CLASS__, 'partiallyHookedWork', null, static function (): void {
            }));
        }
        self::assertTrue($hook(
            __CLASS__,
            'pairHookedWork',
            static function () use ($globals, $context): void {
                $span = $globals::tracerProvider()->getTracer('test')->spanBuilder(self::INSTRUMENTED_SPAN_NAME)->startSpan();
                $context::storage()->attach($span->storeInContext($context::getCurrent()));
            },
            static function () use ($context, $apiSpan): void {
                $scope = $context::storage()->scope();
                if ($scope === null) {
                    return;
                }
                $scope->detach();
                $apiSpan::fromContext($scope->context())->end();
            }
        ));

        self::pairHookedWork();
    }

    public static function appCodeForTestPreOnlyHookKeepsBookkeepingBalanced(): void
    {
        self::appCodeForPartialHookScenario(preOnly: true);
    }

    public static function appCodeForTestPostOnlyHookKeepsBookkeepingBalanced(): void
    {
        self::appCodeForPartialHookScenario(preOnly: false);
    }

    public static function appCodeForTestPostHookAddedLaterKeepsBookkeepingBalanced(): void
    {
        self::appCodeForPartialHookScenario(preOnly: true, postHookAddedLater: true);
    }

    public static function partiallyHookedWork(): void
    {
        self::multiStepUsleep(1);
    }

    public static function pairHookedWork(): void
    {
        // samples taken here make the stack trace id grow before the function below is entered,
        // so a stale or missing entry on the enter/exit stack ends the wrong set of inferred spans
        self::multiStepUsleep(1);
        self::partiallyHookedWork();
        self::innerWork();
    }

    private function implTestPartialHookKeepsBookkeepingBalanced(string $appCodeMethod): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $testCaseHandle = $this->getTestCaseHandle();
        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams): void {
                $appCodeHostParams->setProdOption(OptionForProdName::inferred_spans_enabled, true);
                $appCodeHostParams->setProdOption(OptionForProdName::inferred_spans_sampling_interval, '20ms');
            }
        );
        $appCodeHost->execAppCode(AppCodeTarget::asRouted([__CLASS__, $appCodeMethod]));

        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spansAtLeast(4));
        $dbgCtx->add(compact('agentBackendComms'));

        $rootSpan = IterableUtil::singleValue($agentBackendComms->findRootSpans());
        $agentBackendComms->singleSpanByName(self::INSTRUMENTED_SPAN_NAME);

        $spanIds = [];
        foreach ($agentBackendComms->spans() as $span) {
            self::assertSame($rootSpan->traceId, $span->traceId);
            $spanIds[$span->id] = true;
        }
        foreach ($agentBackendComms->spans() as $span) {
            $dbgCtx->add(compact('span'));
            if ($span->parentId !== null) {
                self::assertArrayHasKey($span->parentId, $spanIds, 'Parent of span ' . $span->name . ' was not exported');
            }
        }
    }

    public function testPreOnlyHookKeepsBookkeepingBalanced(): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTest(__CLASS__, __FUNCTION__),
            fn() => $this->implTestPartialHookKeepsBookkeepingBalanced('appCodeForTestPreOnlyHookKeepsBookkeepingBalanced')
        );
    }

    public function testPostHookAddedLaterKeepsBookkeepingBalanced(): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTest(__CLASS__, __FUNCTION__),
            fn() => $this->implTestPartialHookKeepsBookkeepingBalanced('appCodeForTestPostHookAddedLaterKeepsBookkeepingBalanced')
        );
    }

    public function testPostOnlyHookKeepsBookkeepingBalanced(): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTest(__CLASS__, __FUNCTION__),
            fn() => $this->implTestPartialHookKeepsBookkeepingBalanced('appCodeForTestPostOnlyHookKeepsBookkeepingBalanced')
        );
    }

    /**
     * The hooked internal function calls back into PHP, so the samples taken while it runs create inferred spans (and their scopes)
     * inside the internal function, before its post hook runs.
     */
    public static function appCodeForTestCallbackDrivenInternalFunctionHookWithScope(): void
    {
        $hook = AppCodeContextUtil::adaptClassNameRawStringToScoping('OpenTelemetry\\Instrumentation\\hook');
        self::assertTrue(function_exists($hook));
        $globals = AppCodeContextUtil::adaptClassNameToScoping(Globals::class);
        $context = AppCodeContextUtil::adaptClassNameToScoping(Context::class);
        $apiSpan = AppCodeContextUtil::adaptClassNameToScoping(ApiSpan::class);

        $hookAdded = $hook(
            null,
            'array_walk',
            static function () use ($globals, $context): void {
                if (!self::$armedForCallbackDrivenInternalFunction) {
                    return;
                }
                self::$armedForCallbackDrivenInternalFunction = false;
                self::$startedSpanForCallbackDrivenInternalFunction = true;
                $span = $globals::tracerProvider()->getTracer('test')->spanBuilder(self::CALLBACK_INTERNAL_FUNC_SPAN_NAME)->startSpan();
                $context::storage()->attach($span->storeInContext($context::getCurrent()));
            },
            static function () use ($context, $apiSpan): void {
                if (!self::$startedSpanForCallbackDrivenInternalFunction) {
                    return;
                }
                self::$startedSpanForCallbackDrivenInternalFunction = false;
                $scope = $context::storage()->scope();
                if ($scope === null) {
                    return;
                }
                $scope->detach();
                $apiSpan::fromContext($scope->context())->end();
            }
        );
        self::assertTrue($hookAdded);

        $items = [1];
        self::$armedForCallbackDrivenInternalFunction = true;
        array_walk($items, static function (): void {
            self::multiStepUsleep(self::INSTRUMENTED_WORK_SECONDS);
        });
    }

    public function testCallbackDrivenInternalFunctionHookWithScope(): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTest(__CLASS__, __FUNCTION__),
            function (): void {
                DebugContext::getCurrentScope(/* out */ $dbgCtx);

                $testCaseHandle = $this->getTestCaseHandle();
                $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
                    function (AppCodeHostParams $appCodeHostParams): void {
                        $appCodeHostParams->setProdOption(OptionForProdName::inferred_spans_enabled, true);
                        $appCodeHostParams->setProdOption(OptionForProdName::inferred_spans_sampling_interval, '20ms');
                    }
                );
                $appCodeHost->execAppCode(AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestCallbackDrivenInternalFunctionHookWithScope']));

                // root span + the instrumentation span + at least the inferred spans for the callback and the functions it calls
                $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spansAtLeast(4));
                $dbgCtx->add(compact('agentBackendComms'));

                $rootSpan = IterableUtil::singleValue($agentBackendComms->findRootSpans());
                $agentBackendComms->singleSpanByName(self::CALLBACK_INTERNAL_FUNC_SPAN_NAME);

                $spanIds = [];
                foreach ($agentBackendComms->spans() as $span) {
                    self::assertSame($rootSpan->traceId, $span->traceId);
                    $spanIds[$span->id] = true;
                }
                foreach ($agentBackendComms->spans() as $span) {
                    $dbgCtx->add(compact('span'));
                    if ($span->parentId !== null) {
                        self::assertArrayHasKey($span->parentId, $spanIds, 'Parent of span ' . $span->name . ' was not exported');
                    }
                }
            }
        );
    }

    #[WithSpan(self::WITH_SPAN_NAME)]
    public static function withSpanWork(): void
    {
        self::innerWork();
    }

    public static function appCodeForTestWithSpanIsExportedWhenSampledInsideAttributeHookedFunction(): void
    {
        self::withSpanWork();
    }

    public function testWithSpanIsExportedWhenSampledInsideAttributeHookedFunction(): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTest(__CLASS__, __FUNCTION__),
            function (): void {
                DebugContext::getCurrentScope(/* out */ $dbgCtx);

                $testCaseHandle = $this->getTestCaseHandle();
                $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
                    function (AppCodeHostParams $appCodeHostParams): void {
                        $appCodeHostParams->setProdOption(OptionForProdName::inferred_spans_enabled, true);
                        $appCodeHostParams->setProdOption(OptionForProdName::inferred_spans_sampling_interval, '20ms');
                        $appCodeHostParams->setAdditionalEnvVar('OTEL_PHP_ATTR_HOOKS_ENABLED', 'true');
                    }
                );
                $appCodeHost->execAppCode(AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestWithSpanIsExportedWhenSampledInsideAttributeHookedFunction']));

                $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spansAtLeast(5));
                $dbgCtx->add(compact('agentBackendComms'));

                $rootSpan = IterableUtil::singleValue($agentBackendComms->findRootSpans());
                $agentBackendComms->singleSpanByName(self::WITH_SPAN_NAME);

                $spanIds = [];
                foreach ($agentBackendComms->spans() as $span) {
                    self::assertSame($rootSpan->traceId, $span->traceId);
                    $spanIds[$span->id] = true;
                }
                foreach ($agentBackendComms->spans() as $span) {
                    $dbgCtx->add(compact('span'));
                    if ($span->parentId !== null) {
                        self::assertArrayHasKey($span->parentId, $spanIds, 'Parent of span ' . $span->name . ' was not exported');
                    }
                }
            }
        );
    }

    /**
     * @dataProvider dataProviderForTestInferredSpans
     */
    public function testInferredSpans(MixedMap $testArgs): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs),
            function () use ($testArgs): void {
                $this->implTestInferredSpans($testArgs);
            }
        );
    }
}
