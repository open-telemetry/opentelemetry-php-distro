<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro;

use OpenTelemetry\Distro\HttpTransport\NativeHttpTransportFactory;
use OpenTelemetry\Distro\InferredSpans\InferredSpans;
use OpenTelemetry\Distro\Log\NativeLogWriter;
use OpenTelemetry\Distro\Util\BoolUtil;
use OpenTelemetry\Distro\Util\HiddenConstructorTrait;
use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Registry;
use OpenTelemetry\SDK\SdkAutoloader;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use RuntimeException;
use Throwable;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 *
 * Called by the extension
 */
final class PhpPartFacade
{
    use BootstrapStageLoggingClassTrait;
    /**
     * Constructor is hidden because instance() should be used instead
     */
    use HiddenConstructorTrait;

    public static bool $wasBootstrapCalled = false;

    private static ?self $singletonInstance = null;
    private static bool $rootSpanEnded = false;
    private static ?VendorCustomizationsInterface $vendorCustomizations = null;
    /** @var RemoteConfigConsumerInterface[] */
    private static array $remoteConfigConsumers = [];
    private ?InferredSpans $inferredSpans = null;

    private const IS_DISTRO_ENABLED_ENV_VAR_NAME = 'OTEL_PHP_ENABLED';

    /**
     * Called by the extension
     *
     * @param string $nativePartVersion
     * @param int    $maxEnabledLogLevel
     * @param float  $requestInitStartTime
     *
     * @return bool
     */
    public static function bootstrap(string $nativePartVersion, int $maxEnabledLogLevel, float $requestInitStartTime): bool
    {
        self::$wasBootstrapCalled = true;

        require __DIR__ . DIRECTORY_SEPARATOR . 'BootstrapStageLogger.php';
        require __DIR__ . \DIRECTORY_SEPARATOR . 'Util/StaticClassTrait.php';
        require __DIR__ . \DIRECTORY_SEPARATOR . 'Util/BoolUtil.php';

        BootstrapStageLogger::configure($maxEnabledLogLevel, __DIR__, __NAMESPACE__);
        self::logDebug(__LINE__, __FUNCTION__, 'Starting bootstrap sequence...', compact('nativePartVersion', 'maxEnabledLogLevel', 'requestInitStartTime'));

        if (!self::isDistroEnabled()) {
            self::logCritical(__LINE__, __FUNCTION__, __FUNCTION__ . '() is called but Distro is DISABLED - aborting bootstrap sequence');
            return false;
        }

        if (self::$singletonInstance !== null) {
            self::logCritical(__LINE__, __FUNCTION__, __FUNCTION__ . '() is called even though singleton instance is already created (probably ' . __FUNCTION__ . '() is called more than once)');
            return false;
        }

        try {
            require __DIR__ . DIRECTORY_SEPARATOR . 'AutoloaderDistroOTelClasses.php';
            AutoloaderDistroOTelClasses::register(__NAMESPACE__, __DIR__);

            InstrumentationBridge::singletonInstance()->bootstrap();
            self::prepareForOTelSdk();

            self::registerAutoloaderForVendorDir();

            // RemoteConfigHandler::fetchAndApply depends on OTel SDK so it has to be called after autoloader for OTel SDK is registered
            RemoteConfigHandler::fetchAndApply();
            // OverrideOTelSdkResourceAttributes::register depends on OTel SDK so it has to be called after autoloader for OTel SDK is registered
            OverrideOTelSdkResourceAttributes::register($nativePartVersion, self::$vendorCustomizations);
            self::registerNativeOtlpSerializer();
            self::registerAsyncTransportFactory();
            self::registerOtelLogWriter();

            /** @noinspection PhpInternalEntityUsedInspection */
            if (SdkAutoloader::isExcludedUrl()) {
                self::logDebug(__LINE__, __FUNCTION__, 'URL is excluded');
                return false;
            }

            Traces\RootSpan::startRootSpan(function () {
                PhpPartFacade::$rootSpanEnded = true;
                if (PhpPartFacade::$singletonInstance && PhpPartFacade::$singletonInstance->inferredSpans) {
                    PhpPartFacade::$singletonInstance->inferredSpans->shutdown();
                }
            });

            self::$singletonInstance = new self();

            /**
             * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
             * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
             */
            if (\OpenTelemetry\Distro\get_config_option_by_name('inferred_spans_enabled')) {
                /** @noinspection PhpUnnecessaryFullyQualifiedNameInspection */
                self::$singletonInstance->inferredSpans = new InferredSpans(
                    (bool)\OpenTelemetry\Distro\get_config_option_by_name('inferred_spans_reduction_enabled'),
                    (bool)\OpenTelemetry\Distro\get_config_option_by_name('inferred_spans_stacktrace_enabled'),
                    \OpenTelemetry\Distro\get_config_option_by_name('inferred_spans_min_duration') // @phpstan-ignore argument.type
                );
            }
        } catch (Throwable $throwable) {
            self::logCriticalThrowable(__LINE__, __FUNCTION__, $throwable, 'One of the steps in bootstrap sequence has thrown');
            return false;
        }

        self::logDebug(__LINE__, __FUNCTION__, 'Successfully completed bootstrap sequence');
        return true;
    }

    /**
     * Called by the extension
     *
     * @noinspection PhpUnused
     */
    public static function inferredSpans(int $durationMs, bool $internalFunction): bool
    {
        if (self::$singletonInstance === null) {
            self::logDebug(__LINE__, __FUNCTION__, 'Missing facade');
            return true;
        }

        if (self::$singletonInstance->inferredSpans === null) {
            self::logDebug(__LINE__, __FUNCTION__, 'Missing inferred spans instance');
            return true;
        }
        self::$singletonInstance->inferredSpans->captureStackTrace($durationMs, $internalFunction);

        return true;
    }

    private static function isDistroEnabled(): bool
    {
        return self::getBoolEnvVar(self::IS_DISTRO_ENABLED_ENV_VAR_NAME, default: true);
    }

    public static function getBoolEnvVar(string $envVarName, bool $default): bool
    {
        $envVarVal = getenv($envVarName);
        if (is_string($envVarVal) && (($parsedVal = BoolUtil::parseValue($envVarVal)) !== null)) {
            return $parsedVal;
        }
        return $default;
    }

    /**
     * @param non-empty-string $envVarName
     */
    public static function setEnvVar(string $envVarName, string $envVarValue): void
    {
        if (!putenv($envVarName . '=' . $envVarValue)) {
            throw new RuntimeException('putenv returned false; $envVarName: ' . $envVarName . '; envVarValue: ' . $envVarValue);
        }
    }

    /**
     * Registers vendor-specific customizations. Must be called BEFORE bootstrap().
     */
    public static function setVendorCustomizations(VendorCustomizationsInterface $vendor): void
    {
        self::$vendorCustomizations = $vendor;
    }

    public static function getVendorCustomizations(): ?VendorCustomizationsInterface
    {
        return self::$vendorCustomizations;
    }

    /**
     * Registers a remote config consumer. Must be called BEFORE bootstrap().
     */
    public static function registerRemoteConfigConsumer(RemoteConfigConsumerInterface $consumer): void
    {
        self::$remoteConfigConsumers[] = $consumer;
    }

    /**
     * @return RemoteConfigConsumerInterface[]
     */
    public static function getRemoteConfigConsumers(): array
    {
        return self::$remoteConfigConsumers;
    }

    private static function prepareForOTelSdk(): void
    {
        self::setEnvVar('OTEL_PHP_AUTOLOAD_ENABLED', 'true');
    }

    private static function registerAutoloaderForVendorDir(): void
    {
        $vendorAutoloadPhp = VendorDir::$fullPath . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!file_exists($vendorAutoloadPhp)) {
            throw new RuntimeException("File $vendorAutoloadPhp does not exist");
        }
        self::logDebug(__LINE__, __FUNCTION__, 'Before require', compact('vendorAutoloadPhp'));
        require $vendorAutoloadPhp;

        self::logDebug(__LINE__, __FUNCTION__, 'Finished successfully');
    }

    private static function registerAsyncTransportFactory(): void
    {
        /**
         * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
         * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
         */
        if (\OpenTelemetry\Distro\get_config_option_by_name('async_transport') === false) {
            self::logDebug(__LINE__, __FUNCTION__, 'OTEL_PHP_ASYNC_TRANSPORT set to false');
            return;
        }

        Registry::registerTransportFactory('http', NativeHttpTransportFactory::class, true);
    }

    private static function registerOtelLogWriter(): void
    {
        NativeLogWriter::enableLogWriter();
    }

    private static function registerNativeOtlpSerializer(): void
    {
        /**
         * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
         * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
         */
        if (\OpenTelemetry\Distro\get_config_option_by_name('native_otlp_serializer_enabled') === false) {
            self::logDebug(__LINE__, __FUNCTION__, 'OTEL_PHP_NATIVE_OTLP_SERIALIZER_ENABLED set to false');
        } else {
            // Load classes such as \OpenTelemetry\Contrib\Otlp\SpanExporter to shadow the ones in SDK
            $otelOtlpDir = ProdPhpDir::$fullPath . DIRECTORY_SEPARATOR . 'Contrib' . DIRECTORY_SEPARATOR . 'Otlp';
            foreach (['SpanExporter', 'LogsExporter', 'MetricExporter'] as $exporter) {
                require $otelOtlpDir . DIRECTORY_SEPARATOR . $exporter . '.php';
            }
        }
    }

    /**
     * Called by the extension
     *
     * @noinspection PhpUnused
     */
    public static function handleError(int $type, string $errorFilename, int $errorLineno, string $message): void
    {
        self::logDebug(__LINE__, __FUNCTION__, 'Entered', compact('type', 'errorFilename', 'errorLineno', 'message'));
    }

    /**
     * Called by the extension
     *
     * @noinspection PhpUnused
     */
    public static function shutdown(): void
    {
        self::$singletonInstance = null;
    }

    /**
     * Called by the extension
     *
     * @param array<mixed> $params
     *
     * @noinspection PhpUnused, PhpUnusedParameterInspection
     */
    public static function debugPreHook(mixed $object, array $params, ?string $class, string $function, ?string $filename, ?int $lineno): void
    {
        if (self::$rootSpanEnded) {
            return;
        }

        $tracer = Globals::tracerProvider()->getTracer(
            'io.opentelemetry.php.distro.debug',
            null,
            Version::VERSION_1_25_0->url(),
        );

        $parent = Context::getCurrent();
        /** @noinspection PhpDeprecationInspection */
        $span = $tracer->spanBuilder($class ? $class . "::" . $function : $function) // @phpstan-ignore argument.type
                       ->setSpanKind(SpanKind::KIND_CLIENT)
                       ->setParent($parent)
                       ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
                       ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                       ->setAttribute(TraceAttributes::CODE_FILE_PATH, $filename)
                       ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                       ->setAttribute('call.arguments', print_r($params, true))
                       ->startSpan();

        $context = $span->storeInContext($parent);
        Context::storage()->attach($context);
    }

    /**
     * Called by the extension
     *
     * @param array<mixed> $params
     *
     * @noinspection PhpUnused, PhpUnusedParameterInspection
     */
    public static function debugPostHook(mixed $object, array $params, mixed $retval, ?Throwable $exception): void
    {
        if (self::$rootSpanEnded) {
            return;
        }

        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }

        $scope->detach();
        $span = Span::fromContext($scope->context());
        $span->setAttribute('call.return_value', print_r($retval, true));

        if ($exception) {
            /** @noinspection PhpDeprecationInspection */
            $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $span->end();
    }

    /**
     * Must be defined in class using BootstrapStageLoggingClassTrait
     */
    private static function getCurrentSourceCodeFile(): string
    {
        return __FILE__;
    }

    /**
     * Must be defined in class using BootstrapStageLoggingClassTrait
     */
    private static function getCurrentSourceCodeClass(): string
    {
        return __CLASS__;
    }
}
