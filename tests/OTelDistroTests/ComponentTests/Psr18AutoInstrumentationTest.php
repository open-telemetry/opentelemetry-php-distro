<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Request as GuzzlePsr7Request;
use OTelDistroTests\ComponentTests\Util\AppCodeContextUtil;
use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\AttributesExpectations;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\HttpAppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\HttpClientUtilForTests;
use OTelDistroTests\ComponentTests\Util\OtlpData\SpanKind;
use OTelDistroTests\ComponentTests\Util\PhpSerializationUtil;
use OTelDistroTests\ComponentTests\Util\RequestHeadersRawSnapshotSource;
use OTelDistroTests\ComponentTests\Util\SpanExpectationsBuilder;
use OTelDistroTests\ComponentTests\Util\UrlUtil;
use OTelDistroTests\ComponentTests\Util\WaitForOTelSignalCounts;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\Config\OptionForTestsName;
use OTelDistroTests\Util\DataProviderForTestBuilder;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\GlobalUnderscoreServer;
use OTelDistroTests\Util\HttpMethods;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\MixedMap;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;

/**
 * @group smoke
 * @group does_not_require_external_services
 */
final class Psr18AutoInstrumentationTest extends ComponentTestCaseBase
{
    private const AUTO_INSTRUMENTATION_NAME = 'psr18';
    private const PSR18_INSTRUMENTATION_SCOPE_NAME = 'io.opentelemetry.contrib.php.psr18';

    private const HTTP_APP_CODE_REQUEST_PARAMS_FOR_SERVER_KEY = 'http_app_code_request_params_for_server';
    private const SERVER_RESPONSE_BODY = 'Response from server app code body';
    private const SERVER_RESPONSE_HTTP_STATUS = 234;

    private const ENABLE_PSR18_INSTRUMENTATION_FOR_CLIENT_KEY = 'enable_psr18_instrumentation_for_client';

    public static function appCodeServer(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $dbgCtx->add(['$_SERVER' => IterableUtil::toMap(GlobalUnderscoreServer::getAll())]);

        $dbgCtx->add(['php_sapi_name()' => php_sapi_name()]);
        self::assertNotEquals('cli', php_sapi_name());

        self::assertSame(HttpMethods::GET, GlobalUnderscoreServer::requestMethod());

        http_response_code(self::SERVER_RESPONSE_HTTP_STATUS);
        echo self::SERVER_RESPONSE_BODY;
    }

    public static function appCodeClient(MixedMap $appCodeRequestArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $enablePsr18InstrumentationForClient = $appCodeRequestArgs->getBool(self::ENABLE_PSR18_INSTRUMENTATION_FOR_CLIENT_KEY);
        if ($enablePsr18InstrumentationForClient) {
            $psr18InstrumentationFqClassName = AppCodeContextUtil::adaptClassNameRawStringToScoping('OpenTelemetry\\Contrib\\Instrumentation\\Psr18\\Psr18Instrumentation');
            self::assertTrue(class_exists($psr18InstrumentationFqClassName, autoload: false));
            AssertEx::sameConstValues(constant($psr18InstrumentationFqClassName . '::NAME'), self::AUTO_INSTRUMENTATION_NAME);
        }

        $requestParams = $appCodeRequestArgs->getObject(self::HTTP_APP_CODE_REQUEST_PARAMS_FOR_SERVER_KEY, HttpAppCodeRequestParams::class);

        $dataPerRequestHeaderName = RequestHeadersRawSnapshotSource::optionNameToHeaderName(OptionForTestsName::data_per_request->name);
        $dataPerRequestHeaderValue = PhpSerializationUtil::serializeToString($requestParams->dataPerRequest);

        $client = new GuzzleClient([
            'connect_timeout' => HttpClientUtilForTests::CONNECT_TIMEOUT_SECONDS,
            'timeout' => HttpClientUtilForTests::TIMEOUT_SECONDS,
            'http_errors' => false,
        ]);
        $request = new GuzzlePsr7Request(
            HttpMethods::GET,
            UrlUtil::buildFullUrl($requestParams->urlParts),
            [$dataPerRequestHeaderName => $dataPerRequestHeaderValue],
        );

        $response = $client->sendRequest($request);
        self::assertSame(self::SERVER_RESPONSE_HTTP_STATUS, $response->getStatusCode());
        self::assertSame(self::SERVER_RESPONSE_BODY, (string) $response->getBody());
    }

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestLocalClientServer(): iterable
    {
        return self::adaptDataProviderForTestBuilderToSmokeToDescToMixedMap(
            (new DataProviderForTestBuilder())
                ->addBoolKeyedDimensionAllValuesCombinable(self::ENABLE_PSR18_INSTRUMENTATION_FOR_CLIENT_KEY)
        );
    }

    private function implTestLocalClientServer(MixedMap $testArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $testCaseHandle = $this->getTestCaseHandle();

        $serverAppCode = $testCaseHandle->ensureAdditionalHttpAppCodeHost(
            dbgInstanceName: 'server for PSR-18 request',
            setParamsFunc: function (AppCodeHostParams $appCodeHostParams): void {
                self::disableTimingDependentFeatures($appCodeHostParams);
            }
        );
        $appCodeRequestParamsForServer = $serverAppCode->buildRequestParams(AppCodeTarget::asRouted([__CLASS__, 'appCodeServer']));

        $enablePsr18InstrumentationForClient = $testArgs->getBool(self::ENABLE_PSR18_INSTRUMENTATION_FOR_CLIENT_KEY);
        $clientAppCode = $testCaseHandle->ensureMainAppCodeHost(
            setParamsFunc: function (AppCodeHostParams $appCodeHostParams) use ($enablePsr18InstrumentationForClient): void {
                self::disableTimingDependentFeatures($appCodeHostParams);
                $disabled = [];
                if (!$enablePsr18InstrumentationForClient) {
                    $disabled[] = self::AUTO_INSTRUMENTATION_NAME;
                }
                $appCodeHostParams->setProdOptionIfNotNull(OptionForProdName::disabled_instrumentations, implode(',', $disabled));
            },
            dbgInstanceName: 'client for PSR-18 request',
        );

        $clientAppCode->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeClient']),
            function (AppCodeRequestParams $clientAppCodeReqParams) use ($testArgs, $appCodeRequestParamsForServer): void {
                $clientAppCodeReqParams->setAppCodeRequestArgs(
                    [
                        self::HTTP_APP_CODE_REQUEST_PARAMS_FOR_SERVER_KEY => $appCodeRequestParamsForServer,
                    ]
                    + $testArgs->cloneAsArray()
                );
            }
        );

        $psr18ClientSpanAttributesExpectations = new AttributesExpectations(
            [
                HttpAttributes::HTTP_REQUEST_METHOD => HttpMethods::GET,
                HttpAttributes::HTTP_RESPONSE_STATUS_CODE => self::SERVER_RESPONSE_HTTP_STATUS,
                ServerAttributes::SERVER_ADDRESS => $appCodeRequestParamsForServer->urlParts->host,
                ServerAttributes::SERVER_PORT => $appCodeRequestParamsForServer->urlParts->port,
                UrlAttributes::URL_FULL => UrlUtil::buildFullUrl($appCodeRequestParamsForServer->urlParts),
            ],
        );
        $expectationsForPsr18ClientSpan = (new SpanExpectationsBuilder())
            ->name(HttpMethods::GET)
            ->kind(SpanKind::client)
            ->attributes($psr18ClientSpanAttributesExpectations)
            ->instrumentationScopeName(self::PSR18_INSTRUMENTATION_SCOPE_NAME)
            ->build();

        //
        // Assert
        //

        if ($enablePsr18InstrumentationForClient) {
            // 3 client spans: rootspan + PSR-18 span + curl span.
            // The server rootspan is intentionally not asserted: PSR-18 and curl instrumentations
            // both inject their own traceparent into the outgoing request (they do not coordinate).
            // Which one the server receives varies across PHP versions and is an implementation detail
            // outside the scope of this test.
            $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spansAtLeast(3));
            $dbgCtx->add(compact('agentBackendComms'));

            // PSR-18 span exists with correct attributes
            $psr18ClientSpan = IterableUtil::singleValue($agentBackendComms->findSpansByInstrumentationScope(self::PSR18_INSTRUMENTATION_SCOPE_NAME));
            $expectationsForPsr18ClientSpan->assertMatches($psr18ClientSpan);

            // Client-side hierarchy: PSR-18 span has a parent (client rootspan) and curl is its child
            self::assertNotNull($psr18ClientSpan->parentId);
            $curlClientSpan = $agentBackendComms->singleChildSpan($psr18ClientSpan->id);
            self::assertSame($psr18ClientSpan->traceId, $curlClientSpan->traceId);
        } else {
            $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spansAtLeast(2));
            $dbgCtx->add(compact('agentBackendComms'));

            self::assertEmpty(iterator_to_array($agentBackendComms->findSpansByInstrumentationScope(self::PSR18_INSTRUMENTATION_SCOPE_NAME)));
        }
    }

    /**
     * @dataProvider dataProviderForTestLocalClientServer
     */
    public function testLocalClientServer(MixedMap $testArgs): void
    {
        self::runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs),
            function () use ($testArgs): void {
                $this->implTestLocalClientServer($testArgs);
            }
        );
    }
}
