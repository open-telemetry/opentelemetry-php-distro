<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\AppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\HttpAppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\WaitForOTelSignalCounts;
use PHPUnit\Framework\Assert;

/**
 * @group smoke
 * @group does_not_require_external_services
 */
final class HttpHeaderCaptureTest extends ComponentTestCaseBase
{
    private const CAPTURE_REQUEST_HEADERS_ENV = 'OTEL_INSTRUMENTATION_HTTP_SERVER_CAPTURE_REQUEST_HEADERS';
    private const CAPTURE_RESPONSE_HEADERS_ENV = 'OTEL_INSTRUMENTATION_HTTP_SERVER_CAPTURE_RESPONSE_HEADERS';

    private const REQUEST_HEADER_NAME = 'x-request-id';
    private const REQUEST_HEADER_VALUE = 'test-request-id-abc';
    private const RESPONSE_HEADER_NAME = 'x-custom-response';
    private const RESPONSE_HEADER_VALUE = 'custom-value-xyz';

    public static function appCodeForTestHeaderCapture(): void
    {
        header('X-Custom-Response: ' . self::RESPONSE_HEADER_VALUE);
    }

    private function implTestHttpHeaderCapture(): void
    {
        if ($this->skipIfMainAppCodeHostIsNotHttp()) {
            return;
        }

        $testCaseHandle = $this->getTestCaseHandle();

        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams): void {
                self::disableTimingDependentFeatures($appCodeHostParams);
                $appCodeHostParams->setAdditionalEnvVar(self::CAPTURE_REQUEST_HEADERS_ENV, self::REQUEST_HEADER_NAME);
                $appCodeHostParams->setAdditionalEnvVar(self::CAPTURE_RESPONSE_HEADERS_ENV, self::RESPONSE_HEADER_NAME);
            }
        );

        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestHeaderCapture']),
            function (AppCodeRequestParams $requestParams): void {
                assert($requestParams instanceof HttpAppCodeRequestParams);
                $requestParams->extraHeaders[self::REQUEST_HEADER_NAME] = self::REQUEST_HEADER_VALUE;
            }
        );

        // +1 transaction span (inferred spans disabled)
        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans(1));

        $rootSpan = $agentBackendComms->singleRootSpan();

        Assert::assertSame(
            [self::REQUEST_HEADER_VALUE],
            $rootSpan->attributes->getValue('http.request.header.' . self::REQUEST_HEADER_NAME)
        );

        Assert::assertSame(
            [self::RESPONSE_HEADER_VALUE],
            $rootSpan->attributes->getValue('http.response.header.' . self::RESPONSE_HEADER_NAME)
        );
    }

    public function testHttpHeaderCapture(): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTest(__CLASS__, __FUNCTION__),
            fn() => $this->implTestHttpHeaderCapture()
        );
    }
}
