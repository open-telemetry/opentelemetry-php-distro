<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro;

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
    use BootstrapStageLoggingClassTrait;
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

        /**
         * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
         * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
         */
        $fileNameToContent = \OpenTelemetry\Distro\get_remote_configuration(); // This function is implemented by the extension
        if ($fileNameToContent === null) {
            self::logDebug(__LINE__, __FUNCTION__, 'extension\'s get_remote_configuration() returned null');
            return;
        }

        if (!is_array($fileNameToContent)) { // @phpstan-ignore function.alreadyNarrowedType
            self::logDebug(__LINE__, __FUNCTION__, 'extension\'s get_remote_configuration() return value is not an array; value type: ' . get_debug_type($fileNameToContent));
            return;
        }

        /** @var array<string, string> $fileNameToContent */

        self::logDebug(__LINE__, __FUNCTION__, 'Fetched remote configuration', compact('fileNameToContent'));

        $consumers = PhpPartFacade::getRemoteConfigConsumers();
        if (count($consumers) === 0) {
            self::logDebug(__LINE__, __FUNCTION__, 'No remote config consumers registered - skipping');
            return;
        }

        foreach ($consumers as $consumer) {
            self::logDebug(__LINE__, __FUNCTION__, 'Delegating remote config to consumer', ['consumer' => get_class($consumer)]);
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
        if (OTelSdkConfiguration::has(OTelSdkConfigVariables::OTEL_EXPERIMENTAL_CONFIG_FILE)) {
            $cfgFileOptVal = OTelSdkConfiguration::getMixed(OTelSdkConfigVariables::OTEL_EXPERIMENTAL_CONFIG_FILE);
            self::logError(
                __LINE__,
                __FUNCTION__,
                'Local config has ' . OTelSdkConfigVariables::OTEL_EXPERIMENTAL_CONFIG_FILE . ' option set - remote config feature is not compatible with this option',
                [OTelSdkConfigVariables::OTEL_EXPERIMENTAL_CONFIG_FILE . ' option value' => $cfgFileOptVal],
            );
            return false;
        }

        return true;
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
