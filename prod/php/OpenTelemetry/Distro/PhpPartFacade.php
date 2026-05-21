<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Distro\HttpTransport\NativeHttpTransportFactory;
use OpenTelemetry\Distro\InferredSpans\InferredSpans;
use OpenTelemetry\Distro\Log\LogBackend;
use OpenTelemetry\Distro\Log\LoggingClassTrait;
use OpenTelemetry\Distro\Log\LogFeature;
use OpenTelemetry\Distro\Log\NativeLogWriter;
use OpenTelemetry\Distro\Util\DistroRuntimeException;
use OpenTelemetry\Distro\Util\HiddenConstructorTrait;
use OpenTelemetry\Distro\Util\OTelUtil;
use OpenTelemetry\SDK\Common\Configuration\Configuration as OTelSdkConfiguration;
use OpenTelemetry\SDK\Common\Configuration\Variables as OTelSdkConfigVariables;
use OpenTelemetry\SDK\Registry;
use OpenTelemetry\SDK\SdkAutoloader;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Version;
use Throwable;

/**
 * Called by the extension
 */
final class PhpPartFacade
{
    use LoggingClassTrait;
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

    public const ENABLED_OPT_NAME = 'enabled';
    public const USER_BOOTSTRAP_PHP_FILE_OPT_NAME = 'user_bootstrap_php_file';

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

        LogBackend::initSingletonInstance(new LogBackend(maxEnabledLevel: $maxEnabledLogLevel, sourceCodeRootDirs: [ProdPhpDir::$fullPath]));
        $logDebug = self::logDebug(__FUNCTION__);
        $logDebug?->with(__LINE__, 'Starting bootstrap sequence...', compact('nativePartVersion', 'maxEnabledLogLevel', 'requestInitStartTime'));

        if (!self::isDistroEnabled()) {
            self::logCritical(__FUNCTION__)?->with(__LINE__, __FUNCTION__ . '() is called but Distro is DISABLED - aborting bootstrap sequence');
            return false;
        }

        if (self::$singletonInstance !== null) {
            self::logCritical(__FUNCTION__)?->with(__LINE__, __FUNCTION__ . '() is called even though singleton instance is already created (this function has been called more than once)');
            return false;
        }

        try {
            AutoloaderForClassesInDirectory::register(dirRootNamespace: __NAMESPACE__, dirFullPath: __DIR__);

            InstrumentationBridge::singletonInstance()->bootstrap();
            self::prepareForOTelSdk();

            self::registerAutoloaderForVendorDir();

            // User's bootstrap .php file might register remote config handler so it has to be called before remote config handler
            self::loadUserBootstrapPhpFile();
            // Declarative configuration (OTEL_CONFIG_FILE) and remote configuration (OpAMP) are mutually exclusive
            if (self::isDeclarativeConfigActive()) {
                self::logDebug(__FUNCTION__)?->with(__LINE__, 'Declarative configuration (OTEL_CONFIG_FILE) is active - skipping remote configuration');
            } else {
                // RemoteConfigHandler::fetchAndApply depends on OTel SDK so it has to be called after autoloader for OTel SDK is registered
                RemoteConfigHandler::fetchAndApply();
            }
            // OverrideOTelSdkResourceAttributes::register depends on OTel SDK so it has to be called after autoloader for OTel SDK is registered
            OverrideOTelSdkResourceAttributes::register($nativePartVersion, self::$vendorCustomizations);
            // For file-based config, also register as SPI ComponentProvider so it can be referenced in YAML detectors
            DistroDetectorComponentProvider::registerSpi();
            self::registerNativeOtlpSerializer();
            self::registerAsyncTransportFactory();
            self::registerOtelLogWriter();

            /** @noinspection PhpInternalEntityUsedInspection */
            if (SdkAutoloader::isExcludedUrl()) {
                $logDebug?->with(__LINE__, 'URL is excluded');
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
            self::logCritical(__FUNCTION__)?->withThrowable(__LINE__, 'One of the steps in bootstrap sequence has thrown', $throwable);
            return false;
        }

        $logDebug?->with(__LINE__, 'Successfully completed bootstrap sequence');
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
            self::logDebug(__FUNCTION__)?->with(__LINE__, 'Missing facade');
            return true;
        }

        if (self::$singletonInstance->inferredSpans === null) {
            self::logDebug(__FUNCTION__)?->with(__LINE__, 'Missing inferred spans instance');
            return true;
        }
        self::$singletonInstance->inferredSpans->captureStackTrace($durationMs, $internalFunction);

        return true;
    }

    private static function isDistroEnabled(): bool
    {
        /**
         * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
         * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
         */
        return (bool)\OpenTelemetry\Distro\get_config_option_by_name(self::ENABLED_OPT_NAME);
    }

    private static function isDeclarativeConfigActive(): bool
    {
        return OTelSdkConfiguration::has(OTelSdkConfigVariables::OTEL_CONFIG_FILE);
    }

    /**
     * @param non-empty-string $envVarName
     */
    public static function setEnvVar(string $envVarName, string $envVarValue): void
    {
        if (!putenv($envVarName . '=' . $envVarValue)) {
            throw new DistroRuntimeException('putenv returned false', compact('envVarName', 'envVarValue'));
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

        // Unset COMPOSER_DEV_MODE to prevent OTel SDK's ComposerHandler::isRunning() from returning true,
        // which would skip SdkAutoloader::autoload() and result in no TracerProvider being created.
        // Currently, this is handled by the test infrastructure (AppCodeHostParams::filterBaseEnvVars),
        // but if the issue occurs in production deployments, uncomment the line below.
        // putenv('COMPOSER_DEV_MODE');
    }

    private static function registerAutoloaderForVendorDir(): void
    {
        $vendorAutoloadPhp = VendorDir::$fullPath . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!file_exists($vendorAutoloadPhp)) {
            throw new DistroRuntimeException("File $vendorAutoloadPhp does not exist");
        }
        $logDebug = self::logDebug(__FUNCTION__);
        $logDebug?->with(__LINE__, 'Before require', compact('vendorAutoloadPhp'));
        require $vendorAutoloadPhp;

        $logDebug?->with(__LINE__, 'Finished successfully');
    }

    private static function registerAsyncTransportFactory(): void
    {
        /**
         * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
         * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
         */
        if (\OpenTelemetry\Distro\get_config_option_by_name('async_transport') === false) {
            self::logDebug(__FUNCTION__)?->with(__LINE__, 'OTEL_PHP_ASYNC_TRANSPORT set to false');
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
            self::logDebug(__FUNCTION__)?->with(__LINE__, 'OTEL_PHP_NATIVE_OTLP_SERIALIZER_ENABLED set to false');
        } else {
            // Load classes such as \OpenTelemetry\Contrib\Otlp\SpanExporter to shadow the ones in SDK
            $otelOtlpDir = ProdPhpDir::$fullPath . DIRECTORY_SEPARATOR . 'OpenTelemetry' . DIRECTORY_SEPARATOR . 'Contrib' . DIRECTORY_SEPARATOR . 'Otlp';
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
        self::logDebug(__FUNCTION__)?->with(__LINE__, 'Entered', compact('type', 'errorFilename', 'errorLineno', 'message'));
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
        $fqFunctionName = OTelUtil::buildFqFunctionName($class, $function);
        $span = $tracer->spanBuilder($fqFunctionName) // @phpstan-ignore argument.type
                       ->setSpanKind(SpanKind::KIND_CLIENT)
                       ->setParent($parent)
                       ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, $fqFunctionName)
                       ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                       ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
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
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $span->end();
    }

    private static function loadUserBootstrapPhpFile(): void
    {
        /**
         * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
         * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
         */
        $userBootstrapPhpFile = \OpenTelemetry\Distro\get_config_option_by_name(self::USER_BOOTSTRAP_PHP_FILE_OPT_NAME);
        if (!is_string($userBootstrapPhpFile)) {
            self::logError(__FUNCTION__)?->with(
                __LINE__,
                self::USER_BOOTSTRAP_PHP_FILE_OPT_NAME . ' configuration option value is not a string',
                ['actual type' => get_debug_type($userBootstrapPhpFile), 'actual value' => $userBootstrapPhpFile]
            );
            return;
        }
        $logDebug = self::logDebug(__FUNCTION__);
        if ($userBootstrapPhpFile === '') {
            $logDebug?->with(__LINE__, self::USER_BOOTSTRAP_PHP_FILE_OPT_NAME . ' configuration option is not set');
            return;
        }

        if (!file_exists($userBootstrapPhpFile)) {
            self::logError(__FUNCTION__)?->with(__LINE__, self::USER_BOOTSTRAP_PHP_FILE_OPT_NAME . " configuration option value is a path $userBootstrapPhpFile that does not exist");
            return;
        }
        $logDebug?->with(__LINE__, 'Before require file set by ' . self::USER_BOOTSTRAP_PHP_FILE_OPT_NAME, compact('userBootstrapPhpFile'));
        require $userBootstrapPhpFile;
        $logDebug?->with(__LINE__, 'After require file set by ' . self::USER_BOOTSTRAP_PHP_FILE_OPT_NAME, compact('userBootstrapPhpFile'));
    }

    /**
     * Must be defined in class using LoggingClassTrait
     */
    private static function getCurrentSourceCodeFile(): string
    {
        return __FILE__;
    }

    /**
     * Must be defined in class using LoggingClassTrait
     */
    private static function getCurrentOptionalLogProdFeatureIntOrCategoryString(): int
    {
        return LogFeature::BOOTSTRAP;
    }
}
