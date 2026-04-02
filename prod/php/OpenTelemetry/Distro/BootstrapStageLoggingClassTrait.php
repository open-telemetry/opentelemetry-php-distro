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
        // getCurrentSourceCodeFile() and getCurrentSourceCodeClass() must be defined in class using BootstrapStageLoggingClassTrait
        BootstrapStageLogger::logWithLevel($statementLevel, self::addContextToMessage($message, $context), self::getCurrentSourceCodeFile(), $line, self::getCurrentSourceCodeClass(), $func);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logCritical(int $line, string $func, string $message, array $context = []): void
    {
        // getCurrentSourceCodeFile() and getCurrentSourceCodeClass() must be defined in class using BootstrapStageLoggingClassTrait
        BootstrapStageLogger::logCritical(self::addContextToMessage($message, $context), self::getCurrentSourceCodeFile(), $line, self::getCurrentSourceCodeClass(), $func);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logError(int $line, string $func, string $message, array $context = []): void
    {
        // getCurrentSourceCodeFile() and getCurrentSourceCodeClass() must be defined in class using BootstrapStageLoggingClassTrait
        BootstrapStageLogger::logError(self::addContextToMessage($message, $context), self::getCurrentSourceCodeFile(), $line, self::getCurrentSourceCodeClass(), $func);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logWarning(int $line, string $func, string $message, array $context = []): void
    {
        // getCurrentSourceCodeFile() and getCurrentSourceCodeClass() must be defined in class using BootstrapStageLoggingClassTrait
        BootstrapStageLogger::logWarning(self::addContextToMessage($message, $context), self::getCurrentSourceCodeFile(), $line, self::getCurrentSourceCodeClass(), $func);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logInfo(int $line, string $func, string $message, array $context = []): void
    {
        // getCurrentSourceCodeFile() and getCurrentSourceCodeClass() must be defined in class using BootstrapStageLoggingClassTrait
        BootstrapStageLogger::logInfo(self::addContextToMessage($message, $context), self::getCurrentSourceCodeFile(), $line, self::getCurrentSourceCodeClass(), $func);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logDebug(int $line, string $func, string $message, array $context = []): void
    {
        // getCurrentSourceCodeFile() and getCurrentSourceCodeClass() must be defined in class using BootstrapStageLoggingClassTrait
        BootstrapStageLogger::logDebug(self::addContextToMessage($message, $context), self::getCurrentSourceCodeFile(), $line, self::getCurrentSourceCodeClass(), $func);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logTrace(int $line, string $func, string $message, array $context = []): void
    {
        // getCurrentSourceCodeFile() and getCurrentSourceCodeClass() must be defined in class using BootstrapStageLoggingClassTrait
        BootstrapStageLogger::logTrace(self::addContextToMessage($message, $context), self::getCurrentSourceCodeFile(), $line, self::getCurrentSourceCodeClass(), $func);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnusedPrivateMethodInspection
     */
    private static function logCriticalThrowable(int $line, string $func, Throwable $throwable, string $message, array $context = []): void
    {
        $updatedCtx = ['Throwable' => ['class' => get_class($throwable), 'message' => $throwable->getMessage(), 'stack trace' => $throwable->getTraceAsString()]] + $context;
        // getCurrentSourceCodeFile() and getCurrentSourceCodeClass() must be defined in class using BootstrapStageLoggingClassTrait
        BootstrapStageLogger::logCritical(self::addContextToMessage($message, $updatedCtx), self::getCurrentSourceCodeFile(), $line, self::getCurrentSourceCodeClass(), $func);
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
