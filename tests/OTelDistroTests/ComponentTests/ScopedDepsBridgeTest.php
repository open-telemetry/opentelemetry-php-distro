<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\OtlpData\SpanKind as TestSpanKind;
use OTelDistroTests\ComponentTests\Util\SpanExpectationsBuilder;
use OTelDistroTests\ComponentTests\Util\WaitForOTelSignalCounts;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\DataProviderForTestBuilder;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\MixedMap;

/**
 * @group smoke
 * @group does_not_require_external_services
 */
final class ScopedDepsBridgeTest extends ComponentTestCaseBase
{
    private const BRIDGE_ENABLED_KEY = 'scoped_deps_bridge_enabled';

    private const INSTRUMENTATION_SCOPE_NAME = 'test.scoped_deps_bridge';
    private const CUSTOM_SPAN_NAME = 'test.scoped_deps_bridge.span';

    // -------------------------------------------------------------------------
    // App code — runs in the spawned PHP process
    // -------------------------------------------------------------------------

    /**
     * Mimics the app's own (unscoped) OpenTelemetry usage - either instrumentation the app writes
     * itself, or an officially published auto-instrumentation package it installs on its own.
     *
     * Uses the same probe classes ScopedDepsBridge checks (Globals, Context): when the bridge is
     * enabled these are aliased onto the distro's scoped runtime, so the span shares the active root
     * span's trace/context; when disabled they resolve to the app's own vendor copy and its own
     * separately auto-configured TracerProvider, so the span is exported as its own root.
     */
    public static function appCode(MixedMap $appCodeRequestArgs): void
    {
        $span = Globals::tracerProvider()
            ->getTracer(self::INSTRUMENTATION_SCOPE_NAME)
            ->spanBuilder(self::CUSTOM_SPAN_NAME)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setParent(Context::getCurrent())
            ->startSpan();
        $span->end();
    }

    // -------------------------------------------------------------------------
    // Data provider
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestScopedDepsBridge(): iterable
    {
        return self::adaptDataProviderForTestBuilderToSmokeToDescToMixedMap(
            (new DataProviderForTestBuilder())
                ->addBoolKeyedDimensionAllValuesCombinable(self::BRIDGE_ENABLED_KEY)
        );
    }

    // -------------------------------------------------------------------------
    // Test implementation
    // -------------------------------------------------------------------------

    private function implTestScopedDepsBridge(MixedMap $testArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $bridgeEnabled = $testArgs->getBool(self::BRIDGE_ENABLED_KEY);

        $testCaseHandle = $this->getTestCaseHandle();

        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams) use ($bridgeEnabled): void {
                self::disableTimingDependentFeatures($appCodeHostParams);
                self::ensureTransactionSpanEnabled($appCodeHostParams);
                // The bridge only does anything for scoped builds - force it regardless of the matrix row.
                $appCodeHostParams->setProdOption(OptionForProdName::scoped_deps_enabled, true);
                $appCodeHostParams->setAdditionalEnvVar('OTEL_PHP_SCOPED_DEPS_BRIDGE_ENABLED', $bridgeEnabled ? 'true' : 'false');
            }
        );

        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCode']),
            function (AppCodeRequestParams $appCodeRequestParams) use ($testArgs): void {
                $appCodeRequestParams->setAppCodeRequestArgs($testArgs->cloneAsArray());
            }
        );

        // The custom span is exported either way: the app's own (unscoped) open-telemetry/sdk auto-registers
        // a real TracerProvider via the same OTEL_EXPORTER_OTLP_ENDPOINT the test harness points at the distro
        // (PhpPartFacade forces OTEL_PHP_AUTOLOAD_ENABLED=true before the app's autoloader runs). What the
        // bridge changes is whether that TracerProvider is the distro's own (custom span joins the root's
        // trace) or the app's disconnected one (custom span becomes the root of its own separate trace).
        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans(2));
        $dbgCtx->add(compact('agentBackendComms'));

        $rootSpan = $agentBackendComms->singleSpanByName(self::getExpectedTransactionSpanName());
        $customSpan = $agentBackendComms->singleSpanByName(self::CUSTOM_SPAN_NAME);

        (new SpanExpectationsBuilder())
            ->name(self::CUSTOM_SPAN_NAME)
            ->kind(TestSpanKind::internal)
            ->build()
            ->assertMatches($customSpan);

        if ($bridgeEnabled) {
            self::assertSame($rootSpan->traceId, $customSpan->traceId);
            self::assertTrue($agentBackendComms->isSpanDescendantOf($customSpan, $rootSpan));
        } else {
            self::assertNotSame($rootSpan->traceId, $customSpan->traceId);
            self::assertNull($customSpan->parentId);
        }
    }

    /**
     * @dataProvider dataProviderForTestScopedDepsBridge
     */
    public function testScopedDepsBridge(MixedMap $testArgs): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs),
            function () use ($testArgs): void {
                $this->implTestScopedDepsBridge($testArgs);
            }
        );
    }
}
