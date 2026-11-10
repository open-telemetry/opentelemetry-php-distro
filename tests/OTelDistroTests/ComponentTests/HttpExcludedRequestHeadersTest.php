<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\HttpAppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\WaitForOTelSignalCounts;
use PHPUnit\Framework\Assert;

/**
 * Requests excluded by OTEL_PHP_EXCLUDED_URLS and OTEL_PHP_EXCLUDED_REQUEST_HEADERS (implemented by the SDK) are not traced.
 *
 * The test infra loads its own (unscoped) SDK into the app code, so spans that the app code creates itself say nothing
 * about the SDK bundled in the distro. Instead, an excluded request makes an outgoing curl call, which only the
 * distro's (scoped) curl instrumentation can turn into a span. If the SDK were not fully off, that span would appear.
 *
 * @group smoke
 * @group does_not_require_external_services
 */
final class HttpExcludedRequestHeadersTest extends ComponentTestCaseBase
{
    private const MARKER_HEADER = 'x-marker';

    public static function appCodeForTestExcluded(): void
    {
        $marker = $_SERVER['HTTP_X_MARKER'] ?? '';
        if (!is_string($marker) || !str_starts_with($marker, 'excluded')) {
            return;
        }
        // The built-in server is single-threaded so the call goes to a closed port: it fails at once, but the curl
        // instrumentation still turns it into a client span, which must not appear for an excluded request
        $curl = curl_init('http://127.0.0.1:9/');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 5);
        curl_exec($curl);
        curl_close($curl);
    }

    /**
     * @param array<string, string> $envVars
     * @param list<array{path: string, headers: array<string, string>}> $excludedRequests
     */
    private function runExcluded(array $envVars, array $excludedRequests): void
    {
        if ($this->skipIfMainAppCodeHostIsNotHttp()) {
            return;
        }

        $testCaseHandle = $this->getTestCaseHandle();
        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams) use ($envVars): void {
                self::disableTimingDependentFeatures($appCodeHostParams);
                $appCodeHostParams->setAdditionalEnvVar('OTEL_INSTRUMENTATION_HTTP_SERVER_CAPTURE_REQUEST_HEADERS', self::MARKER_HEADER);
                foreach ($envVars as $name => $value) {
                    $appCodeHostParams->setAdditionalEnvVar($name, $value);
                }
            }
        );

        foreach ($excludedRequests as $request) {
            $appCodeHost->execAppCode(
                AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestExcluded']),
                function (AppCodeRequestParams $requestParams) use ($request): void {
                    assert($requestParams instanceof HttpAppCodeRequestParams);
                    $requestParams->urlParts->path = $request['path'];
                    $requestParams->extraHeaders = $request['headers'];
                }
            );
        }
        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestExcluded']),
            function (AppCodeRequestParams $requestParams): void {
                assert($requestParams instanceof HttpAppCodeRequestParams);
                $requestParams->extraHeaders = ['user-agent' => 'curl/8', self::MARKER_HEADER => 'traced'];
            }
        );

        // The excluded requests are sent first, a span of any of them would exceed the single expected span
        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans(1));
        $rootSpan = $agentBackendComms->singleRootSpan();
        Assert::assertSame(['traced'], $rootSpan->attributes->getValue('http.request.header.' . self::MARKER_HEADER));
    }

    public function testExcludedRequestHeaders(): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTest(__CLASS__, __FUNCTION__),
            fn() => $this->runExcluded(
                ['OTEL_PHP_EXCLUDED_REQUEST_HEADERS' => 'User-Agent: kube-probe*, X-Skip-Tracing'],
                [
                    ['path' => '/', 'headers' => ['x-skip-tracing' => '1', self::MARKER_HEADER => 'excluded-1']],
                    ['path' => '/', 'headers' => ['user-agent' => 'kube-probe/1.28', self::MARKER_HEADER => 'excluded-2']],
                ]
            )
        );
    }

    public function testExcludedUrls(): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTest(__CLASS__, __FUNCTION__),
            fn() => $this->runExcluded(
                ['OTEL_PHP_EXCLUDED_URLS' => '/healthcheck'],
                [['path' => '/healthcheck', 'headers' => [self::MARKER_HEADER => 'excluded-url']]]
            )
        );
    }
}
