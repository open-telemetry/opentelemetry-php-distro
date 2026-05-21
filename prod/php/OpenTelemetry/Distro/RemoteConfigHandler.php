<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro;

use OpenTelemetry\Distro\Log\LoggingClassTrait;
use OpenTelemetry\Distro\Log\LogFeature;
use OpenTelemetry\Distro\Util\StaticClassTrait;
use OpenTelemetry\SDK\Common\Configuration\Configuration as OTelSdkConfiguration;
use OpenTelemetry\SDK\Common\Configuration\Variables as OTelSdkConfigVariables;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class RemoteConfigHandler
{
    use LoggingClassTrait;
    use StaticClassTrait;

    /**
     * Called by the extension
     *
     * @noinspection PhpUnused
     */
    public static function fetchAndApply(): void
    {
        if (!self::verifyLocalConfigCompatible()) {
            return;
        }

        $logDebug = self::logDebug(__FUNCTION__);

        /**
         * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
         * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
         */
        $fileNameToContent = \OpenTelemetry\Distro\get_remote_configuration(); // This function is implemented by the extension
        if ($fileNameToContent === null) {
            $logDebug?->with(__LINE__, 'extension\'s get_remote_configuration() returned null');
            return;
        }

        if (!is_array($fileNameToContent)) { // @phpstan-ignore function.alreadyNarrowedType
            $logDebug?->with(__LINE__, 'extension\'s get_remote_configuration() return value is not an array; value type: ' . get_debug_type($fileNameToContent));
            return;
        }

        /** @var array<string, string> $fileNameToContent */

        $logDebug?->with(__LINE__, 'Fetched remote configuration', compact('fileNameToContent'));

        $consumers = PhpPartFacade::getRemoteConfigConsumers();
        if (count($consumers) === 0) {
            $logDebug?->with(__LINE__, 'No remote config consumers registered - skipping');
            return;
        }

        foreach ($consumers as $consumer) {
            $logDebug?->with(__LINE__, 'Delegating remote config to consumer', ['consumer' => get_class($consumer)]);
            $consumer->applyRemoteConfig($fileNameToContent);
        }
    }

    /**
     * Called by the extension
     *
     * @noinspection PhpUnused
     */
    private static function verifyLocalConfigCompatible(): bool
    {
        if (OTelSdkConfiguration::has(OTelSdkConfigVariables::OTEL_CONFIG_FILE)) {
            $cfgFileOptVal = OTelSdkConfiguration::getMixed(OTelSdkConfigVariables::OTEL_CONFIG_FILE);
            self::logDebug(__FUNCTION__)?->with(
                __LINE__,
                'Local config has ' . OTelSdkConfigVariables::OTEL_CONFIG_FILE . ' option set - remote config is not compatible with declarative configuration',
                [OTelSdkConfigVariables::OTEL_CONFIG_FILE . ' option value' => $cfgFileOptVal],
            );
            return false;
        }

        return true;
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
        return LogFeature::CONFIG;
    }
}
