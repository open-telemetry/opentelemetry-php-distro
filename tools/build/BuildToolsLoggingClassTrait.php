<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\DistroTools\Build;

use OpenTelemetry\Distro\Log\LogLevel;
use Throwable;

/**
 * @phpstan-import-type Context from BuildToolsLog
 */
trait BuildToolsLoggingClassTrait
{
    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logWithLevel(LogLevel $level, int $line, string $fqMethod, string $msg, array $context = []): void
    {
        // getCurrentSourceCodeFile() must be defined in class using BuildToolsLoggingClassTrait
        BuildToolsLog::withLevel($level, self::getCurrentSourceCodeFile(), $line, $fqMethod, $msg, $context);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logCritical(int $line, string $fqMethod, string $msg, array $context = []): void
    {
        self::logWithLevel(LogLevel::critical, $line, $fqMethod, $msg, $context);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logError(int $line, string $fqMethod, string $msg, array $context = []): void
    {
        self::logWithLevel(LogLevel::error, $line, $fqMethod, $msg, $context);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logWarning(int $line, string $fqMethod, string $msg, array $context = []): void
    {
        self::logWithLevel(LogLevel::warning, $line, $fqMethod, $msg, $context);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logInfo(int $line, string $fqMethod, string $msg, array $context = []): void
    {
        self::logWithLevel(LogLevel::info, $line, $fqMethod, $msg, $context);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logDebug(int $line, string $fqMethod, string $msg, array $context = []): void
    {
        self::logWithLevel(LogLevel::debug, $line, $fqMethod, $msg, $context);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logTrace(int $line, string $fqMethod, string $msg, array $context = []): void
    {
        self::logWithLevel(LogLevel::trace, $line, $fqMethod, $msg, $context);
    }

    private static function logThrowable(LogLevel $level, int $line, string $fqMethod, string $throwableDesc, Throwable $throwable): void
    {
        // getCurrentSourceCodeFile() must be defined in class using BuildToolsLoggingClassTrait
        BuildToolsLog::logThrowable($level, self::getCurrentSourceCodeFile(), $line, $fqMethod, $throwableDesc, $throwable);
    }
}
