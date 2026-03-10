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

        $fileNameToContent = get_remote_configuration(); // This function is implemented by the extension
        if ($fileNameToContent === null) {
            self::logDebug('extension\'s get_remote_configuration() returned null', __LINE__, __FUNCTION__);
            return;
        }

        if (!is_array($fileNameToContent)) { // @phpstan-ignore function.alreadyNarrowedType
            self::logDebug('extension\'s get_remote_configuration() return value is not an array; value type: ' . get_debug_type($fileNameToContent), __LINE__, __FUNCTION__);
            return;
        }

        self::logDebug('Returned array: ' . self::valueToDbgString($fileNameToContent), __LINE__, __FUNCTION__);
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
            if (!is_scalar($cfgFileOptVal)) {
                $cfgFileOptVal = self::valueToDbgString($cfgFileOptVal);
            }
            self::logError(
                'Local config has ' . OTelSdkConfigVariables::OTEL_EXPERIMENTAL_CONFIG_FILE . ' option set - remote config feature is not compatible with this option'
                . '; ' . OTelSdkConfigVariables::OTEL_EXPERIMENTAL_CONFIG_FILE . ' option value: ' . $cfgFileOptVal,
                __LINE__,
                __FUNCTION__,
            );
            return false;
        }

        return true;
    }

    private static function logDebug(string $message, int $lineNumber, string $func): void
    {
        self::logWithLevel(BootstrapStageLogger::LEVEL_DEBUG, $message, $lineNumber, $func);
    }

    private static function logError(string $message, int $lineNumber, string $func): void
    {
        self::logWithLevel(BootstrapStageLogger::LEVEL_ERROR, $message, $lineNumber, $func);
    }

    private static function logWithLevel(int $statementLevel, string $message, int $lineNumber, string $func): void
    {
        BootstrapStageLogger::logWithFeatureAndLevel(Log\LogFeature::OPAMP, $statementLevel, $message, __FILE__, $lineNumber, __CLASS__, $func);
    }

    public static function valueToDbgString(mixed $value): string
    {
        $options = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES;
        $encodedData = json_encode($value, $options);
        if ($encodedData === false) {
            return 'json_encode() failed'
                   . '. json_last_error_msg(): ' . json_last_error_msg()
                   . '. data type: ' . get_debug_type($value);
        }
        return $encodedData;
    }
}
