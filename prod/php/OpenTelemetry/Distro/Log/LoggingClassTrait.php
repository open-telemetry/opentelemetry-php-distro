<?php

declare(strict_types=1);

namespace OpenTelemetry\Distro\Log;

trait LoggingClassTrait
{
    public static function isLogEnabledForLevel(LogLevel $level): bool
    {
        return LogBackend::getSingletonInstance()->isEnabledForLevel($level);
    }

    public static function logWithLevel(string $func, LogLevel $level): ?EnabledLogProxy
    {
        return LogBackend::getSingletonInstance()->isEnabledForLevel($level)
            ? new EnabledLogProxy(
                file: self::getCurrentSourceCodeFile() /* <- must be defined in class using LoggingClassTrait */,
                func: $func,
                featureOrCategory: self::getCurrentOptionalLogProdFeatureIntOrCategoryString() /* <- must be defined in class using LoggingClassTrait */,
                level: $level,
            )
            : null;
    }

    private static function logCritical(string $func): ?EnabledLogProxy
    {
        return LogBackend::getSingletonInstance()->isEnabledForLevel(LogLevel::critical)
            ? new EnabledLogProxy(
                file: self::getCurrentSourceCodeFile() /* <- must be defined in class using LoggingClassTrait */,
                func: $func,
                featureOrCategory: self::getCurrentOptionalLogProdFeatureIntOrCategoryString() /* <- must be defined in class using LoggingClassTrait */,
                level: LogLevel::critical,
            )
            : null;
    }

    private static function logError(string $func): ?EnabledLogProxy
    {
        return LogBackend::getSingletonInstance()->isEnabledForLevel(LogLevel::error)
            ? new EnabledLogProxy(
                file: self::getCurrentSourceCodeFile() /* <- must be defined in class using LoggingClassTrait */,
                func: $func,
                featureOrCategory: self::getCurrentOptionalLogProdFeatureIntOrCategoryString() /* <- must be defined in class using LoggingClassTrait */,
                level: LogLevel::error,
            )
            : null;
    }

    private static function logWarning(string $func): ?EnabledLogProxy
    {
        return LogBackend::getSingletonInstance()->isEnabledForLevel(LogLevel::warning)
            ? new EnabledLogProxy(
                file: self::getCurrentSourceCodeFile() /* <- must be defined in class using LoggingClassTrait */,
                func: $func,
                featureOrCategory: self::getCurrentOptionalLogProdFeatureIntOrCategoryString() /* <- must be defined in class using LoggingClassTrait */,
                level: LogLevel::warning,
            )
            : null;
    }

    private static function logInfo(string $func): ?EnabledLogProxy
    {
        return LogBackend::getSingletonInstance()->isEnabledForLevel(LogLevel::info)
            ? new EnabledLogProxy(
                file: self::getCurrentSourceCodeFile() /* <- must be defined in class using LoggingClassTrait */,
                func: $func,
                featureOrCategory: self::getCurrentOptionalLogProdFeatureIntOrCategoryString() /* <- must be defined in class using LoggingClassTrait */,
                level: LogLevel::info,
            )
            : null;
    }

    private static function logDebug(string $func): ?EnabledLogProxy
    {
        return LogBackend::getSingletonInstance()->isEnabledForLevel(LogLevel::debug)
            ? new EnabledLogProxy(
                file: self::getCurrentSourceCodeFile() /* <- must be defined in class using LoggingClassTrait */,
                func: $func,
                featureOrCategory: self::getCurrentOptionalLogProdFeatureIntOrCategoryString() /* <- must be defined in class using LoggingClassTrait */,
                level: LogLevel::debug,
            )
            : null;
    }

    private static function logTrace(string $func): ?EnabledLogProxy
    {
        return LogBackend::getSingletonInstance()->isEnabledForLevel(LogLevel::trace)
            ? new EnabledLogProxy(
                file: self::getCurrentSourceCodeFile() /* <- must be defined in class using LoggingClassTrait */,
                func: $func,
                featureOrCategory: self::getCurrentOptionalLogProdFeatureIntOrCategoryString() /* <- must be defined in class using LoggingClassTrait */,
                level: LogLevel::trace,
            )
            : null;
    }
}
