<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\HttpAppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\WaitForOTelSignalCounts;
use OTelDistroTests\Util\HttpMethods;
use OTelDistroTests\Util\MixedMap;
use PHPUnit\Framework\Assert;

/**
 * Request body capturing is implemented by the SDK root span (AutoRootSpan + HttpBodyCapture).
 *
 * @group smoke
 * @group does_not_require_external_services
 */
final class HttpBodyCaptureTest extends ComponentTestCaseBase
{
    private const CAPTURE_REQUEST_BODY_ENV = 'OTEL_PHP_INSTRUMENTATION_HTTP_SERVER_CAPTURE_REQUEST_BODY';
    private const CAPTURE_REQUEST_BODY_MAX_SIZE_ENV = 'OTEL_PHP_INSTRUMENTATION_HTTP_SERVER_CAPTURE_REQUEST_BODY_MAX_SIZE';

    private const BODY_ATTRIBUTE_NAME = 'http.request.body.content';
    private const EXPECTED_BODY_LENGTH_HEADER = 'X-Expected-Body-Length';
    private const MAX_SIZE = 10;

    private const CAPTURE_ENABLED_KEY = 'capture_enabled';
    private const BODY_KEY = 'body';
    private const CONTENT_TYPE_KEY = 'content_type';
    private const EXPECTED_VALUE_KEY = 'expected_value';

    /**
     * The application reads the whole body after the root span was started, as real code does.
     * It fails (HTTP 500) if capturing took any of it away.
     */
    public static function appCodeForTestBodyCapture(): void
    {
        $body = file_get_contents('php://input');
        $expectedLengthHeaderValue = $_SERVER['HTTP_X_EXPECTED_BODY_LENGTH'] ?? null;
        $expectedLength = is_numeric($expectedLengthHeaderValue) ? (int)$expectedLengthHeaderValue : -1;
        if (strlen((string)$body) !== $expectedLength) {
            throw new \RuntimeException('Application got ' . strlen((string)$body) . ' bytes of the body instead of ' . $expectedLength);
        }
    }

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestBodyCapture(): iterable
    {
        /**
         * @return iterable<array<string, mixed>>
         */
        $generateDataSets = function (): iterable {
            $dataSet = fn(bool $enabled, string $body, string $contentType, ?string $expected): array => [
                self::CAPTURE_ENABLED_KEY => $enabled,
                self::BODY_KEY => $body,
                self::CONTENT_TYPE_KEY => $contentType,
                self::EXPECTED_VALUE_KEY => $expected,
            ];
            yield $dataSet(false, '{"foo":"bar"}', 'application/json', null); // not captured by default
            yield $dataSet(true, '{"foo":"bar"}', 'application/json', '{"foo":"ba'); // cut at the limit
            yield $dataSet(true, 'hello', 'text/plain', 'hello');
            yield $dataSet(true, str_repeat('a', 3000), 'text/plain', str_repeat('a', self::MAX_SIZE));
            yield $dataSet(true, "\x89PNG\x00\x01", 'application/octet-stream', null); // binary is skipped
        };

        return self::adaptDataSetsGeneratorToSmokeToDescToMixedMap($generateDataSets);
    }

    private function implTestBodyCapture(MixedMap $testArgs): void
    {
        $captureEnabled = $testArgs->getBool(self::CAPTURE_ENABLED_KEY);
        $body = $testArgs->getString(self::BODY_KEY);
        $contentType = $testArgs->getString(self::CONTENT_TYPE_KEY);
        $expectedAttributeValue = $testArgs->getNullableString(self::EXPECTED_VALUE_KEY);

        if ($this->skipIfMainAppCodeHostIsNotHttp()) {
            return;
        }

        $testCaseHandle = $this->getTestCaseHandle();

        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams) use ($captureEnabled): void {
                self::disableTimingDependentFeatures($appCodeHostParams);
                if ($captureEnabled) {
                    $appCodeHostParams->setAdditionalEnvVar(self::CAPTURE_REQUEST_BODY_ENV, 'true');
                    $appCodeHostParams->setAdditionalEnvVar(self::CAPTURE_REQUEST_BODY_MAX_SIZE_ENV, (string)self::MAX_SIZE);
                }
            }
        );

        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestBodyCapture']),
            function (AppCodeRequestParams $requestParams) use ($body, $contentType): void {
                assert($requestParams instanceof HttpAppCodeRequestParams);
                $requestParams->httpRequestMethod = HttpMethods::POST;
                $requestParams->body = $body;
                $requestParams->extraHeaders['Content-Type'] = $contentType;
                $requestParams->extraHeaders[self::EXPECTED_BODY_LENGTH_HEADER] = (string)strlen($body);
            }
        );

        // +1 transaction span (inferred spans disabled)
        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans(1));

        $rootSpan = $agentBackendComms->singleRootSpan();

        if ($expectedAttributeValue === null) {
            Assert::assertFalse($rootSpan->attributes->keyExists(self::BODY_ATTRIBUTE_NAME));
        } else {
            Assert::assertSame($expectedAttributeValue, $rootSpan->attributes->getValue(self::BODY_ATTRIBUTE_NAME));
        }
    }

    /**
     * @dataProvider dataProviderForTestBodyCapture
     */
    public function testBodyCapture(MixedMap $testArgs): void
    {
        $this->runAndEscalateLogLevelOnFailure(self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs), fn() => $this->implTestBodyCapture($testArgs));
    }
}
