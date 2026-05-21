<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use CurlHandle;
use OTelDistroTests\ComponentTests\Util\AppCodeContextUtil;
use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\AttributesExpectations;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\CurlHandleForTests;
use OTelDistroTests\ComponentTests\Util\HttpAppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\HttpClientUtilForTests;
use OTelDistroTests\ComponentTests\Util\OtlpData\Span;
use OTelDistroTests\ComponentTests\Util\OtlpData\SpanKind;
use OTelDistroTests\ComponentTests\Util\PhpSerializationUtil;
use OTelDistroTests\ComponentTests\Util\RequestHeadersRawSnapshotSource;
use OTelDistroTests\ComponentTests\Util\ResourcesCleanerClient;
use OTelDistroTests\ComponentTests\Util\SpanExpectationsBuilder;
use OTelDistroTests\ComponentTests\Util\UrlUtil;
use OTelDistroTests\ComponentTests\Util\WaitForOTelSignalCounts;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\Config\OptionForTestsName;
use OTelDistroTests\Util\DataProviderForTestBuilder;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\GlobalUnderscoreServer;
use OTelDistroTests\Util\HttpMethods;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\MixedMap;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\RangeUtil;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;

/**
 * @group smoke
 * @group does_not_require_external_services
 */
final class CurlAutoInstrumentationTest extends ComponentTestCaseBase
{
    private const AUTO_INSTRUMENTATION_NAME = 'curl';

    private const RESOURCES_CLEANER_CLIENT_KEY = 'resources_cleaner_client';
    private const HTTP_APP_CODE_REQUEST_PARAMS_FOR_SERVER_KEY = 'http_app_code_request_params_for_server';
    private const HTTP_REQUEST_HEADER_NAME_PREFIX = 'OTel_PHP_distro_custom_header_';
    private const SERVER_RESPONSE_BODY = 'Response from server app code body';
    private const SERVER_RESPONSE_HTTP_STATUS = 234;

    private const ENABLE_CURL_INSTRUMENTATION_FOR_CLIENT_KEY = 'enable_curl_instrumentation_for_client';
    private const ENABLE_CURL_INSTRUMENTATION_FOR_SERVER_KEY = 'enable_curl_instrumentation_for_server';

    /**
     * @param iterable<int> $suffixes
     *
     * @return array<string, string>
     */
    private static function genHeaders(iterable $suffixes): array
    {
        $result = [];
        foreach ($suffixes as $suffix) {
            $headerName = self::HTTP_REQUEST_HEADER_NAME_PREFIX . $suffix;
            $result[$headerName] = 'Value_for_' . $headerName;
        }
        return $result;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private static function convertHeadersToCurlFormat(array $headers): array
    {
        $result = [];
        foreach ($headers as $headerName => $headerValue) {
            $result[] = $headerName . ': ' . $headerValue;
        }
        return $result;
    }

    public static function appCodeServer(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $dbgCtx->add(['$_SERVER' => IterableUtil::toMap(GlobalUnderscoreServer::getAll())]);

        $dbgCtx->add(['php_sapi_name()' => php_sapi_name()]);
        self::assertNotEquals('cli', php_sapi_name());

        self::assertSame(HttpMethods::GET, GlobalUnderscoreServer::requestMethod());

        $expectedHeaders = self::genHeaders(RangeUtil::generateFromToIncluding(2, 3));
        foreach ($expectedHeaders as $expectedHeaderName => $expectedHeaderValue) {
            $dbgCtx->add(compact('expectedHeaderName', 'expectedHeaderValue'));
            self::assertSame($expectedHeaderValue, GlobalUnderscoreServer::getRequestHeaderValue($expectedHeaderName));
        }

        http_response_code(self::SERVER_RESPONSE_HTTP_STATUS);
        echo self::SERVER_RESPONSE_BODY;
    }

    public static function appCodeClient(MixedMap $appCodeRequestArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        self::assertTrue(extension_loaded('curl'));

        $enableCurlInstrumentationForClient = $appCodeRequestArgs->getBool(self::ENABLE_CURL_INSTRUMENTATION_FOR_CLIENT_KEY);
        if ($enableCurlInstrumentationForClient) {
            $curlInstrumentationFqClassName = AppCodeContextUtil::adaptClassNameRawStringToScoping('OpenTelemetry\\Contrib\\Instrumentation\\Curl\\CurlInstrumentation');
            self::assertTrue(class_exists($curlInstrumentationFqClassName, autoload: false));
            AssertEx::sameConstValues(constant($curlInstrumentationFqClassName . '::NAME'), self::AUTO_INSTRUMENTATION_NAME);
        }

        $requestParams = $appCodeRequestArgs->getObject(self::HTTP_APP_CODE_REQUEST_PARAMS_FOR_SERVER_KEY, HttpAppCodeRequestParams::class);
        $resourcesCleanerClient = $appCodeRequestArgs->getObject(self::RESOURCES_CLEANER_CLIENT_KEY, ResourcesCleanerClient::class);

        $curlHandleRaw = curl_init(UrlUtil::buildFullUrl($requestParams->urlParts));
        self::assertInstanceOf(CurlHandle::class, $curlHandleRaw);
        $curlHandle = new CurlHandleForTests($curlHandleRaw, $resourcesCleanerClient);

        self::assertTrue($curlHandle->setOpt(CURLOPT_CONNECTTIMEOUT, HttpClientUtilForTests::CONNECT_TIMEOUT_SECONDS));
        self::assertTrue($curlHandle->setOpt(CURLOPT_TIMEOUT, HttpClientUtilForTests::TIMEOUT_SECONDS));

        $dataPerRequestHeaderName = RequestHeadersRawSnapshotSource::optionNameToHeaderName(OptionForTestsName::data_per_request->name);
        $dataPerRequestHeaderValue = PhpSerializationUtil::serializeToString($requestParams->dataPerRequest);

        $notFinalHeaders12 = self::genHeaders([1, 2]);
        $notFinalHeader2Key = array_key_last($notFinalHeaders12);
        self::assertNotNull($notFinalHeader2Key);
        $notFinalHeaders12[$notFinalHeader2Key] .= '_NOT_FINAL_VALUE';
        self::assertTrue($curlHandle->setOptArray([CURLOPT_HTTPHEADER => self::convertHeadersToCurlFormat($notFinalHeaders12), CURLOPT_POST => true]));

        $headers = array_merge([$dataPerRequestHeaderName => $dataPerRequestHeaderValue], self::genHeaders([2, 3]));
        self::assertTrue($curlHandle->setOptArray([CURLOPT_HTTPHEADER => self::convertHeadersToCurlFormat($headers), CURLOPT_HTTPGET => true, CURLOPT_RETURNTRANSFER => true]));

        $execRetVal = $curlHandle->exec();
        $dbgCtx->add(compact('execRetVal'));
        if ($execRetVal === false) {
            self::fail(LoggableToString::convert(['error' => $curlHandle->error(), 'errno' => $curlHandle->errno(), 'verbose output' => $curlHandle->lastVerboseOutput()]));
        }
        $dbgCtx->add(['getInfo()' => $curlHandle->getInfo()]);

        self::assertSame(self::SERVER_RESPONSE_HTTP_STATUS, $curlHandle->getResponseStatusCode());
        self::assertSame(self::SERVER_RESPONSE_BODY, $execRetVal);
    }

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestLocalClientServer(): iterable
    {
        return self::adaptDataProviderForTestBuilderToSmokeToDescToMixedMap(
            (new DataProviderForTestBuilder())
                ->addBoolKeyedDimensionAllValuesCombinable(self::ENABLE_CURL_INSTRUMENTATION_FOR_CLIENT_KEY)
                ->addBoolKeyedDimensionAllValuesCombinable(self::ENABLE_CURL_INSTRUMENTATION_FOR_SERVER_KEY)
        );
    }

    private function implTestLocalClientServer(MixedMap $testArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $testCaseHandle = $this->getTestCaseHandle();

        $enableCurlInstrumentationForServer = $testArgs->getBool(self::ENABLE_CURL_INSTRUMENTATION_FOR_SERVER_KEY);
        $serverAppCode = $testCaseHandle->ensureAdditionalHttpAppCodeHost(
            dbgInstanceName: 'server for cUrl request',
            setParamsFunc: function (AppCodeHostParams $appCodeHostParams) use ($enableCurlInstrumentationForServer): void {
                self::disableTimingDependentFeatures($appCodeHostParams);
                if (!$enableCurlInstrumentationForServer) {
                    $appCodeHostParams->setProdOptionIfNotNull(OptionForProdName::disabled_instrumentations, self::AUTO_INSTRUMENTATION_NAME);
                }
            }
        );
        $appCodeRequestParamsForServer = $serverAppCode->buildRequestParams(AppCodeTarget::asRouted([__CLASS__, 'appCodeServer']));

        $enableCurlInstrumentationForClient = $testArgs->getBool(self::ENABLE_CURL_INSTRUMENTATION_FOR_CLIENT_KEY);
        $clientAppCode = $testCaseHandle->ensureMainAppCodeHost(
            setParamsFunc: function (AppCodeHostParams $appCodeHostParams) use ($enableCurlInstrumentationForClient): void {
                self::disableTimingDependentFeatures($appCodeHostParams);
                if (!$enableCurlInstrumentationForClient) {
                    $appCodeHostParams->setProdOptionIfNotNull(OptionForProdName::disabled_instrumentations, self::AUTO_INSTRUMENTATION_NAME);
                }
            },
            dbgInstanceName: 'client for cUrl request',
        );
        $resourcesCleanerClient = $testCaseHandle->getResourcesCleanerClient();

        $clientAppCode->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeClient']),
            function (AppCodeRequestParams $clientAppCodeReqParams) use ($testArgs, $appCodeRequestParamsForServer, $resourcesCleanerClient): void {
                $clientAppCodeReqParams->setAppCodeRequestArgs(
                    [
                        self::HTTP_APP_CODE_REQUEST_PARAMS_FOR_SERVER_KEY => $appCodeRequestParamsForServer,
                        self::RESOURCES_CLEANER_CLIENT_KEY => $resourcesCleanerClient,
                    ]
                    + $testArgs->cloneAsArray()
                );
            }
        );

        //
        // spans: <client app code transaction span> -> <curl client span> -> <server app code transaction span>
        //        |------------------------------------------------------|    |--------------------------------|
        //        client app host                                             server app host

        $curlClientSpanAttributesExpectations = new AttributesExpectations(
            [
                CodeAttributes::CODE_FUNCTION_NAME => 'curl_exec',
                HttpAttributes::HTTP_REQUEST_METHOD => HttpMethods::GET,
                HttpAttributes::HTTP_RESPONSE_STATUS_CODE => self::SERVER_RESPONSE_HTTP_STATUS,
                ServerAttributes::SERVER_ADDRESS => $appCodeRequestParamsForServer->urlParts->host,
                ServerAttributes::SERVER_PORT => $appCodeRequestParamsForServer->urlParts->port,
                UrlAttributes::URL_FULL => UrlUtil::buildFullUrl($appCodeRequestParamsForServer->urlParts),
                UrlAttributes::URL_SCHEME => $appCodeRequestParamsForServer->urlParts->scheme,
            ],
        );
        $expectationsForCurlClientSpan = (new SpanExpectationsBuilder())->name(HttpMethods::GET)->kind(SpanKind::client)->attributes($curlClientSpanAttributesExpectations)->build();

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

        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans($enableCurlInstrumentationForClient ? 3 : 2));
        $dbgCtx->add(compact('agentBackendComms'));

        //
        // Assert
        //

        if ($enableCurlInstrumentationForClient) {
            $rootSpan = $agentBackendComms->singleRootSpan();
            foreach ($agentBackendComms->spans() as $span) {
                self::assertSame($rootSpan->traceId, $span->traceId);
            }
            $curlClientSpan = $agentBackendComms->singleChildSpan($rootSpan->id);
            $expectationsForCurlClientSpan->assertMatches($curlClientSpan);
            $serverTxSpan = $agentBackendComms->singleChildSpan($curlClientSpan->id);
        } else {
            $serverTxSpan = IterableUtil::singleValue($agentBackendComms->findSpansWithAttributeValue(ServerAttributes::SERVER_PORT, $appCodeRequestParamsForServer->urlParts->port));
            self::assertNull($serverTxSpan->parentId);
            $clientTxSpan = IterableUtil::singleValue(IterableUtil::findByPredicateOnValue($agentBackendComms->spans(), fn(Span $span) => $span->parentId === null && $span !== $serverTxSpan));
            self::assertNotEquals($serverTxSpan->traceId, $clientTxSpan->traceId);
        }

        $expectationsForServerTxSpan->assertMatches($serverTxSpan);
        self::assertSame($enableCurlInstrumentationForClient, $serverTxSpan->hasRemoteParent());
    }

    /**
     * @dataProvider dataProviderForTestLocalClientServer
     */
    public function testLocalClientServer(MixedMap $testArgs): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs),
            function () use ($testArgs): void {
                $this->implTestLocalClientServer($testArgs);
            }
        );
    }
}
