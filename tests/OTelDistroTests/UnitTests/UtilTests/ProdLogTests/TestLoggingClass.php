<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests\ProdLogTests;

use OpenTelemetry\Distro\Log\EnabledLogProxy;
use OpenTelemetry\Distro\Log\LoggingClassTrait;
use OpenTelemetry\Distro\Log\LogLevel;

final class TestLoggingClass
{
    use LoggingClassTrait;

    public static string $srcCodeFile = __FILE__;
    public static null|int|string $feature = null;

    public static function invokeLog(LogLevel $level, null|int|string $featureOrCategory, string $file, string $func): ?EnabledLogProxy
    {
        $srcCodeFileToRestore = self::$srcCodeFile;
        $featureToRestore = self::$feature;

        self::$srcCodeFile = $file;
        self::$feature = $featureOrCategory;

        try {
            return match ($level) {
                LogLevel::critical => self::logCritical($func),
                LogLevel::error => self::logError($func),
                LogLevel::warning => self::logWarning($func),
                LogLevel::info => self::logInfo($func),
                LogLevel::debug => self::logDebug($func),
                LogLevel::trace => self::logTrace($func),
                default => self::logWithLevel($func, $level),
            };
        } finally {
            self::$srcCodeFile = $srcCodeFileToRestore;
            self::$feature = $featureToRestore;
        }
    }

    /**
     * Must be defined in class using LoggingClassTrait
     */
    private static function getCurrentSourceCodeFile(): string
    {
        return self::$srcCodeFile;
    }

    /**
     * Must be defined in class using LoggingClassTrait
     */
    private static function getCurrentOptionalLogProdFeatureIntOrCategoryString(): null|int|string
    {
        return self::$feature;
    }
}
