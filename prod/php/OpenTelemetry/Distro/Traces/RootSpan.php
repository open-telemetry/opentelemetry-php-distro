<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\Traces;

use OpenTelemetry\Distro\Util\ArrayUtil;
use OpenTelemetry\Distro\Util\TextUtil;
use Http\Discovery\Exception\NotFoundException;
use Http\Discovery\Psr17FactoryDiscovery;
use Nyholm\Psr7Server\ServerRequestCreator;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Util\ShutdownHandler;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use Psr\Http\Message\ServerRequestInterface;
use OpenTelemetry\Distro\Util\WildcardListMatcher;

class RootSpan
{
    use LogsMessagesTrait;

    private const DEFAULT_SPAN_NAME_FOR_SCRIPT = '<script>';

    private static function isCliSapi(): bool
    {
        return php_sapi_name() === 'cli';
    }

    public static function startRootSpan(?callable $notifySpanEnded): void
    {
        if (self::isCliSapi()) {
            if (!Configuration::getBoolean('OTEL_PHP_TRANSACTION_SPAN_ENABLED_CLI', true)) {
                self::logDebug('OTEL_PHP_TRANSACTION_SPAN_ENABLED_CLI set to false');
                return;
            }
        } elseif (!Configuration::getBoolean('OTEL_PHP_TRANSACTION_SPAN_ENABLED', true)) {
            self::logDebug('OTEL_PHP_TRANSACTION_SPAN_ENABLED set to false');
            return;
        }

        $request = self::createRequest();
        if ($request) {
            self::create($request);
            self::registerShutdownHandler($request, $notifySpanEnded);
        } else {
            self::logWarning('Unable to create server request');
        }
    }

    private static function getStartTime(ServerRequestInterface $request): float
    {
        if (ArrayUtil::getValueIfKeyExists('REQUEST_TIME_FLOAT', $request->getServerParams(), /* out */ $serverRequestTime)) {
            if (is_float($serverRequestTime)) {
                return $serverRequestTime;
            }
            if (is_string($serverRequestTime)) {
                return floatval($serverRequestTime);
            }
        }
        return microtime(true);
    }

    /**
     * @psalm-suppress ArgumentTypeCoercion
     * @internal
     */
    private static function create(ServerRequestInterface $request): void
    {
        $tracer = Globals::tracerProvider()->getTracer(
            'io.opentelemetry.php.distro.rootspan',
            null,
            Version::VERSION_1_25_0->url(),
        );
        $parent = Globals::propagator()->extract($request->getHeaders());
        $spanBuilder = $tracer->spanBuilder(self::getSpanName($request))
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setStartTimestamp((int) (self::getStartTime($request) * 1_000_000_000))
            ->setParent($parent);
        if (!self::isCliSapi()) {
            $spanBuilder->setAttributes(
                [
                    TraceAttributes::URL_FULL               => strval($request->getUri()),
                    TraceAttributes::HTTP_REQUEST_METHOD    => $request->getMethod(),
                    TraceAttributes::HTTP_REQUEST_BODY_SIZE => $request->getHeaderLine('Content-Length'),
                    TraceAttributes::USER_AGENT_ORIGINAL    => $request->getHeaderLine('User-Agent'),
                    TraceAttributes::SERVER_ADDRESS         => $request->getUri()->getHost(),
                    TraceAttributes::SERVER_PORT            => $request->getUri()->getPort(),
                    TraceAttributes::URL_SCHEME             => $request->getUri()->getScheme(),
                    TraceAttributes::URL_PATH               => $request->getUri()->getPath(),
                ]
            );
        }
        $span = $spanBuilder->startSpan();
        Context::storage()->attach($span->storeInContext($parent));
    }

    /**
     * @internal
     */
    private static function createRequest(): ?ServerRequestInterface
    {
        try {
            $creator = new ServerRequestCreator(
                Psr17FactoryDiscovery::findServerRequestFactory(),
                Psr17FactoryDiscovery::findUriFactory(),
                Psr17FactoryDiscovery::findUploadedFileFactory(),
                Psr17FactoryDiscovery::findStreamFactory(),
            );

            return $creator->fromGlobals();
        } catch (NotFoundException $e) {
            self::logError('Unable to initialize server request creator for auto root span creation', ['exception' => $e]);
        }

        return null;
    }

    /**
     * @internal
     */
    private static function registerShutdownHandler(ServerRequestInterface $request, ?callable $notifySpanEnded): void
    {
        $shutdownFunc =
            function () use ($request, $notifySpanEnded) {
                if ($notifySpanEnded) {
                    $notifySpanEnded();
                }
                self::shutdownHandler($request);
            };

        ShutdownHandler::register($shutdownFunc(...));
    }

    /**
     * @internal
     */
    public static function shutdownHandler(ServerRequestInterface $request): void
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            self::logDebug('Root span not created or ended too early');
            return;
        }
        $scope->detach();
        $span = Span::fromContext($scope->context());

        if (is_int(http_response_code())) {
            $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, http_response_code());
        } elseif (ArrayUtil::getValueIfKeyExists('REDIRECT_STATUS', $request->getServerParams(), /* out */ $redirectStatus)) {
            if (is_int($redirectStatus)) {
                $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $redirectStatus);
            }
        }

        $span->end();
    }

    private static function getOptionalServerVarElement(string $key): mixed
    {
        /** @noinspection PhpIssetCanBeReplacedWithCoalesceInspection */
        return isset($_SERVER[$key]) ? $_SERVER[$key] : null;
    }

    /**
     * @return non-empty-string
     */
    private static function getSpanName(ServerRequestInterface $request): string
    {
        if (php_sapi_name() === 'cli') {
            if (is_string($scriptName = self::getOptionalServerVarElement('SCRIPT_NAME'))) {
                $processedScriptName = self::processPathMatchers($scriptName);
                return TextUtil::isEmptyString($processedScriptName) ? self::DEFAULT_SPAN_NAME_FOR_SCRIPT : $processedScriptName;
            } else {
                return self::DEFAULT_SPAN_NAME_FOR_SCRIPT;
            }
        }

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        return $method . ' ' . self::processPathMatchers($path);
    }

    private static function processPathMatchers(string $path): string
    {
        /** @var string[] $groups */
        $groups = Configuration::getList('OTEL_PHP_TRANSACTION_URL_GROUPS', []);
        if (count($groups) == 0) {
            return $path;
        }

        $matcher = new WildcardListMatcher($groups);
        return $matcher->match($path) ?? $path;
    }
}
