<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\AttributesExpectations;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\OtlpData\SpanKind as TestSpanKind;
use OTelDistroTests\ComponentTests\Util\SpanExpectationsBuilder;
use OTelDistroTests\ComponentTests\Util\WaitForOTelSignalCounts;
use OTelDistroTests\Util\DataProviderForTestBuilder;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\MixedMap;

/**
 * @group smoke
 * @group does_not_require_external_services
 */
final class WithSpanAttributeTest extends ComponentTestCaseBase
{
    private const ATTR_HOOKS_ENABLED_KEY = 'attr_hooks_enabled';

    // Keys used by appCode methods
    private const SCENARIO_KEY        = 'scenario';
    private const SCENARIO_BASIC      = 'basic';
    private const SCENARIO_CUSTOM     = 'custom';
    private const SCENARIO_PARAM_ATTR = 'param_attr';
    private const SCENARIO_PROP_ATTR  = 'prop_attr';
    private const SCENARIO_EXCEPTION  = 'exception';

    // Values passed to app code for scenarios
    private const USER_ID_VALUE    = 'user-42';
    private const OPERATION_VALUE  = 'create';
    private const TENANT_ID_VALUE  = 'acme-corp';

    // -------------------------------------------------------------------------
    // App code — runs in the spawned PHP process
    // -------------------------------------------------------------------------

    /**
     * Service class used as app code subject — lives here so it's available
     * in the spawned process via the test class autoloader.
     */

    public static function appCode(MixedMap $appCodeRequestArgs): void
    {
        $scenario = $appCodeRequestArgs->getString(self::SCENARIO_KEY);
        $service  = new WithSpanTestService();
        $service->tenantId = self::TENANT_ID_VALUE;

        switch ($scenario) {
            case self::SCENARIO_BASIC:
                $service->basicMethod();
                break;

            case self::SCENARIO_CUSTOM:
                $service->customNameAndKind();
                break;

            case self::SCENARIO_PARAM_ATTR:
                $service->withParamAttrs(self::USER_ID_VALUE, 'secret-password', self::OPERATION_VALUE);
                break;

            case self::SCENARIO_PROP_ATTR:
                $service->withPropertyAttrs();
                break;

            case self::SCENARIO_EXCEPTION:
                try {
                    $service->throwingMethod('test-failure');
                } catch (\RuntimeException) {
                    // exception expected — let the span be ended with ERROR status
                }
                break;

            default:
                throw new \InvalidArgumentException("Unknown scenario: {$scenario}");
        }
    }

    // -------------------------------------------------------------------------
    // Data provider
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestWithSpan(): iterable
    {
        return self::adaptDataProviderForTestBuilderToSmokeToDescToMixedMap(
            (new DataProviderForTestBuilder())
                ->addBoolKeyedDimensionAllValuesCombinable(self::ATTR_HOOKS_ENABLED_KEY)
                ->addKeyedDimensionAllValuesCombinable(self::SCENARIO_KEY, [
                    self::SCENARIO_BASIC,
                    self::SCENARIO_CUSTOM,
                    self::SCENARIO_PARAM_ATTR,
                    self::SCENARIO_PROP_ATTR,
                    self::SCENARIO_EXCEPTION,
                ])
        );
    }

    // -------------------------------------------------------------------------
    // Test implementation
    // -------------------------------------------------------------------------

    private function implTestWithSpan(MixedMap $testArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $attrHooksEnabled = $testArgs->getBool(self::ATTR_HOOKS_ENABLED_KEY);
        $scenario         = $testArgs->getString(self::SCENARIO_KEY);

        $testCaseHandle = $this->getTestCaseHandle();

        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams) use ($attrHooksEnabled): void {
                self::disableTimingDependentFeatures($appCodeHostParams);
                $appCodeHostParams->setAdditionalEnvVar('OTEL_PHP_ATTR_HOOKS_ENABLED', $attrHooksEnabled ? 'true' : 'false');
            }
        );

        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCode']),
            function (AppCodeRequestParams $appCodeRequestParams) use ($testArgs): void {
                $appCodeRequestParams->setAppCodeRequestArgs($testArgs->cloneAsArray());
            }
        );

        // When enabled: 1 root transaction span + 1 #[WithSpan] span
        $expectedSpanCount = $attrHooksEnabled ? 2 : 1;
        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(
            WaitForOTelSignalCounts::spans($expectedSpanCount)
        );
        $dbgCtx->add(compact('agentBackendComms'));

        $rootSpan = IterableUtil::singleValue($agentBackendComms->findRootSpans());

        if (!$attrHooksEnabled) {
            // Only root span present; no #[WithSpan] span
            self::assertCount(1, IterableUtil::toList($agentBackendComms->spans()));
            return;
        }

        $withSpanSpan = $agentBackendComms->singleChildSpan($rootSpan->id);
        $dbgCtx->add(compact('withSpanSpan'));

        switch ($scenario) {
            case self::SCENARIO_BASIC:
                (new SpanExpectationsBuilder())
                    ->name(WithSpanTestService::class . '::basicMethod')
                    ->kind(TestSpanKind::internal)
                    ->attributes(new AttributesExpectations([
                        'code.function'  => 'basicMethod',
                        'code.namespace' => WithSpanTestService::class,
                    ]))
                    ->build()
                    ->assertMatches($withSpanSpan);
                break;

            case self::SCENARIO_CUSTOM:
                (new SpanExpectationsBuilder())
                    ->name('custom.operation')
                    ->kind(TestSpanKind::client)
                    ->attributes(new AttributesExpectations([
                        'code.function'  => 'customNameAndKind',
                        'code.namespace' => WithSpanTestService::class,
                    ]))
                    ->build()
                    ->assertMatches($withSpanSpan);
                break;

            case self::SCENARIO_PARAM_ATTR:
                (new SpanExpectationsBuilder())
                    ->name(WithSpanTestService::class . '::withParamAttrs')
                    ->kind(TestSpanKind::internal)
                    ->attributes(new AttributesExpectations([
                        'code.function'    => 'withParamAttrs',
                        'userId'           => self::USER_ID_VALUE,
                        'request.operation' => self::OPERATION_VALUE,
                    ]))
                    ->addNotAllowedAttribute('secret')  // password must not be captured
                    ->build()
                    ->assertMatches($withSpanSpan);
                break;

            case self::SCENARIO_PROP_ATTR:
                (new SpanExpectationsBuilder())
                    ->name(WithSpanTestService::class . '::withPropertyAttrs')
                    ->kind(TestSpanKind::internal)
                    ->attributes(new AttributesExpectations([
                        'code.function' => 'withPropertyAttrs',
                        'tenantId'      => self::TENANT_ID_VALUE,
                    ]))
                    ->build()
                    ->assertMatches($withSpanSpan);
                break;

            case self::SCENARIO_EXCEPTION:
                // Verify the span was created and param attribute captured;
                // status ERROR and exception event are set by WithSpanHandler::post
                // but are not yet captured by the test infrastructure's Span class.
                (new SpanExpectationsBuilder())
                    ->name(WithSpanTestService::class . '::throwingMethod')
                    ->kind(TestSpanKind::internal)
                    ->attributes(new AttributesExpectations([
                        'code.function' => 'throwingMethod',
                        'reason'        => 'test-failure',
                    ]))
                    ->build()
                    ->assertMatches($withSpanSpan);
                break;
        }
    }

    /**
     * @dataProvider dataProviderForTestWithSpan
     */
    public function testWithSpan(MixedMap $testArgs): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs),
            function () use ($testArgs): void {
                $this->implTestWithSpan($testArgs);
            }
        );
    }
}
