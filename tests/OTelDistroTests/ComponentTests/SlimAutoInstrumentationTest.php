<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OTelDistroTests\ComponentTests\Util\AppCodeContextUtil;
use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\OtlpData\SpanKind;
use OTelDistroTests\ComponentTests\Util\SpanExpectationsBuilder;
use OTelDistroTests\ComponentTests\Util\WaitForOTelSignalCounts;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\DataProviderForTestBuilder;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\MixedMap;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * @group smoke
 * @group does_not_require_external_services
 */
final class SlimAutoInstrumentationTest extends ComponentTestCaseBase
{
    private const AUTO_INSTRUMENTATION_NAME = 'slim';
    private const SLIM_INSTRUMENTATION_SCOPE_NAME = 'io.opentelemetry.contrib.php.slim';
    private const IS_AUTO_INSTRUMENTATION_ENABLED_KEY = 'is_auto_instrumentation_enabled';

    private const ROUTE_NAME = 'hello';
    private const RESPONSE_BODY = 'Hello, world!';

    public static function appCodeForTestAutoInstrumentation(MixedMap $appCodeRequestArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $isAutoInstrumentationEnabled = $appCodeRequestArgs->getBool(self::IS_AUTO_INSTRUMENTATION_ENABLED_KEY);
        if ($isAutoInstrumentationEnabled) {
            $slimInstrumentationFqClassName = AppCodeContextUtil::adaptClassNameRawStringToScoping('OpenTelemetry\\Contrib\\Instrumentation\\Slim\\SlimInstrumentation');
            $dbgCtx->add(compact('slimInstrumentationFqClassName'));
            self::assertTrue(class_exists($slimInstrumentationFqClassName, autoload: false));
            AssertEx::sameConstValues(constant($slimInstrumentationFqClassName . '::NAME'), self::AUTO_INSTRUMENTATION_NAME);
        }

        // Drives Slim directly with a hand-built PSR-7 request — no real HTTP
        // server needed, App::handle() is a plain PHP call either way.
        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        $app->get('/hello/{name}', function (ServerRequestInterface $request, ResponseInterface $response, array $args) {
            /** @var string $name */
            $name = $args['name'];
            $response->getBody()->write("Hello, {$name}!");

            return $response;
        })->setName(self::ROUTE_NAME);

        $requestFactory = new ServerRequestFactory();
        $response = $app->handle($requestFactory->createServerRequest('GET', '/hello/world'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::RESPONSE_BODY, (string) $response->getBody());
    }

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestAutoInstrumentation(): iterable
    {
        return self::adaptDataProviderForTestBuilderToSmokeToDescToMixedMap(
            (new DataProviderForTestBuilder())
                ->addBoolKeyedDimensionAllValuesCombinable(self::IS_AUTO_INSTRUMENTATION_ENABLED_KEY)
        );
    }

    private function implTestAutoInstrumentation(MixedMap $testArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $isAutoInstrumentationEnabled = $testArgs->getBool(self::IS_AUTO_INSTRUMENTATION_ENABLED_KEY);

        $testCaseHandle = $this->getTestCaseHandle();

        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams) use ($isAutoInstrumentationEnabled): void {
                if (!$isAutoInstrumentationEnabled) {
                    $appCodeHostParams->setProdOptionIfNotNull(OptionForProdName::disabled_instrumentations, self::AUTO_INSTRUMENTATION_NAME);
                }
                self::disableTimingDependentFeatures($appCodeHostParams);
            }
        );
        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestAutoInstrumentation']),
            function (AppCodeRequestParams $appCodeRequestParams) use ($testArgs): void {
                $appCodeRequestParams->setAppCodeRequestArgs($testArgs->cloneAsArray());
            }
        );

        if ($isAutoInstrumentationEnabled) {
            // +1 automatic local root span, +1 App::handle span, +1 route-callable span
            $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans(3));
            $dbgCtx->add(compact('agentBackendComms'));

            $rootSpan = $agentBackendComms->singleRootSpan();
            $slimServerSpan = $agentBackendComms->singleChildSpan($rootSpan->id);
            $slimRouteCallableSpan = $agentBackendComms->singleChildSpan($slimServerSpan->id);

            $expectationsForSlimServerSpan = (new SpanExpectationsBuilder())
                ->name('GET ' . self::ROUTE_NAME)
                ->kind(SpanKind::server)
                ->instrumentationScopeName(self::SLIM_INSTRUMENTATION_SCOPE_NAME)
                ->build();
            $expectationsForSlimServerSpan->assertMatches($slimServerSpan);

            $expectationsForSlimRouteCallableSpan = (new SpanExpectationsBuilder())
                ->kind(SpanKind::internal)
                ->instrumentationScopeName(self::SLIM_INSTRUMENTATION_SCOPE_NAME)
                ->build();
            $expectationsForSlimRouteCallableSpan->assertMatches($slimRouteCallableSpan);
        } else {
            // +1 automatic local root span only
            $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans(1));
            $dbgCtx->add(compact('agentBackendComms'));

            self::assertEmpty(iterator_to_array($agentBackendComms->findSpansByInstrumentationScope(self::SLIM_INSTRUMENTATION_SCOPE_NAME)));
        }
    }

    /**
     * @dataProvider dataProviderForTestAutoInstrumentation
     */
    public function testAutoInstrumentation(MixedMap $testArgs): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs),
            function () use ($testArgs): void {
                $this->implTestAutoInstrumentation($testArgs);
            }
        );
    }
}
