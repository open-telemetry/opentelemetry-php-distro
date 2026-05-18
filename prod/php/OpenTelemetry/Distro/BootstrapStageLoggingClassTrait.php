<?php

declare(strict_types=1);

namespace OpenTelemetry\Distro;

use JsonException;
use Throwable;

use function json_encode;

/**
 * @phpstan-type Context array<string, mixed>
 */
trait BootstrapStageLoggingClassTrait
{
    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    public static function logWithLevel(int $statementLevel, int $line, string $func, string $message, array $context = []): void
    {
        BootstrapStageLogger::logWithFeatureAndLevel(
            self::getCurrentLogFeature() /* <- must be defined in class using BootstrapStageLoggingClassTrait */,
            $statementLevel,
            self::addContextToMessage($message, $context),
            self::getCurrentSourceCodeFile() /* <- must be defined in class using BootstrapStageLoggingClassTrait */,
            $line,
            self::getCurrentSourceCodeClass() /* <- must be defined in class using BootstrapStageLoggingClassTrait */,
            $func
        );
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logCritical(int $line, string $func, string $message, array $context = []): void
    {
        self::logWithLevel(BootstrapStageLogger::LEVEL_CRITICAL, $line, $func, $message, $context);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logError(int $line, string $func, string $message, array $context = []): void
    {
        self::logWithLevel(BootstrapStageLogger::LEVEL_ERROR, $line, $func, $message, $context);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logWarning(int $line, string $func, string $message, array $context = []): void
    {
        self::logWithLevel(BootstrapStageLogger::LEVEL_WARNING, $line, $func, $message, $context);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logInfo(int $line, string $func, string $message, array $context = []): void
    {
        self::logWithLevel(BootstrapStageLogger::LEVEL_INFO, $line, $func, $message, $context);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logDebug(int $line, string $func, string $message, array $context = []): void
    {
        self::logWithLevel(BootstrapStageLogger::LEVEL_DEBUG, $line, $func, $message, $context);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logTrace(int $line, string $func, string $message, array $context = []): void
    {
        self::logWithLevel(BootstrapStageLogger::LEVEL_TRACE, $line, $func, $message, $context);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logCriticalThrowable(int $line, string $func, Throwable $throwable, string $message, array $context = []): void
    {
        $updatedCtx = ['Throwable' => ['class' => get_class($throwable), 'message' => $throwable->getMessage(), 'stack trace' => $throwable->getTraceAsString()]] + $context;
        self::logCritical($line, $func, $message, $updatedCtx);
    }

    /**
     * @param Context $context
     */
    private static function addContextToMessage(string $message, array $context = []): string
    {
        if (count($context) === 0) {
            return $message;
        }

        try {
            $jsonEncodedCtx = json_encode($context, /* flags: */ JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $jsonEncodedCtx = 'Failed to JSON encode context: ' . $exception->getMessage();
        }

        return $message . '; ' . $jsonEncodedCtx;
    }
}
