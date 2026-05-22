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
use OTelDistroTests\ComponentTests\Util\OtlpData\Span;
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
    private const CURL_AUTO_INSTRUMENTATION_NAME = 'curl';

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
                // Guzzle uses curl under the hood; disable curl instrumentation on the client to assert PSR-18 span in isolation.
                $disabled = [self::CURL_AUTO_INSTRUMENTATION_NAME];
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

        //
        // spans: <client app code transaction span> -> <PSR-18 client span> -> <server app code transaction span>
        //        |--------------------------------------------------------|    |--------------------------------|
        //        client app host                                               server app host

        $psr18ClientSpanAttributesExpectations = new AttributesExpectations(
            [
                HttpAttributes::HTTP_REQUEST_METHOD => HttpMethods::GET,
                HttpAttributes::HTTP_RESPONSE_STATUS_CODE => self::SERVER_RESPONSE_HTTP_STATUS,
                ServerAttributes::SERVER_ADDRESS => $appCodeRequestParamsForServer->urlParts->host,
                ServerAttributes::SERVER_PORT => $appCodeRequestParamsForServer->urlParts->port,
                UrlAttributes::URL_FULL => UrlUtil::buildFullUrl($appCodeRequestParamsForServer->urlParts),
            ],
        );
        $expectationsForPsr18ClientSpan = (new SpanExpectationsBuilder())->name(HttpMethods::GET)->kind(SpanKind::client)->attributes($psr18ClientSpanAttributesExpectations)->build();

        $serverTxSpanAttributesExpectations = new AttributesExpectations(
            [
                HttpAttributes::HTTP_REQUEST_METHOD => HttpMethods::GET,
                HttpAttributes::HTTP_RESPONSE_STATUS_CODE => self::SERVER_RESPONSE_HTTP_STATUS,
                ServerAttributes::SERVER_ADDRESS => $appCodeRequestParamsForServer->urlParts->host,
                ServerAttributes::SERVER_PORT => $appCodeRequestParamsForServer->urlParts->port,
                UrlAttributes::URL_FULL => UrlUtil::buildFullUrl($appCodeRequestParamsForServer->urlParts),
                UrlAttributes::URL_PATH => $appCodeRequestParamsForServer->urlParts->path,
                UrlAttributes::URL_SCHEME => $appCodeRequestParamsForServer->urlParts->scheme,
            ],
        );
        $expectedServerTxSpanName = HttpMethods::GET . ' ' . $appCodeRequestParamsForServer->urlParts->path;
        $expectationsForServerTxSpan = (new SpanExpectationsBuilder())->name($expectedServerTxSpanName)->kind(SpanKind::server)->attributes($serverTxSpanAttributesExpectations)->build();

        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans($enablePsr18InstrumentationForClient ? 3 : 2));
        $dbgCtx->add(compact('agentBackendComms'));

        //
        // Assert
        //

        if ($enablePsr18InstrumentationForClient) {
            $rootSpan = $agentBackendComms->singleRootSpan();
            foreach ($agentBackendComms->spans() as $span) {
                self::assertSame($rootSpan->traceId, $span->traceId);
            }
            $psr18ClientSpan = $agentBackendComms->singleChildSpan($rootSpan->id);
            $expectationsForPsr18ClientSpan->assertMatches($psr18ClientSpan);
            $serverTxSpan = $agentBackendComms->singleChildSpan($psr18ClientSpan->id);
        } else {
            $serverTxSpan = IterableUtil::singleValue($agentBackendComms->findSpansWithAttributeValue(ServerAttributes::SERVER_PORT, $appCodeRequestParamsForServer->urlParts->port));
            self::assertNull($serverTxSpan->parentId);
            $clientTxSpan = IterableUtil::singleValue(IterableUtil::findByPredicateOnValue($agentBackendComms->spans(), fn(Span $span) => $span->parentId === null && $span !== $serverTxSpan));
            self::assertNotEquals($serverTxSpan->traceId, $clientTxSpan->traceId);
        }

        $expectationsForServerTxSpan->assertMatches($serverTxSpan);
        self::assertSame($enablePsr18InstrumentationForClient, $serverTxSpan->hasRemoteParent());
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
