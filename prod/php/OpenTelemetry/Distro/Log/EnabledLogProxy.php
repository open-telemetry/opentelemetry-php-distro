<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\Log;

use OpenTelemetry\Distro\Util\GetContextInterface;
use Throwable;

/**
 * @phpstan-import-type Context from GetContextInterface
 *
 * @phpstan-import-type FormatAndWrite from LogBackend
 */
final class EnabledLogProxy
{
    public function __construct(
        private readonly string $file,
        private readonly string $func,
        private readonly null|int|string $featureOrCategory,
        private readonly LogLevel $level,
    ) {
    }

    /**
     * @phpstan-param Context $context
     */
    public function with(int $line, string $message, array $context = []): void
    {
        LogBackend::getSingletonInstance()
            ->write(file: $this->file, line: $line, func: $this->func, featureOrCategory: $this->featureOrCategory, level: $this->level, message: $message, context: $context);
    }

    /**
     * @phpstan-param Context $context
     */
    public function withThrowable(int $line, string $message, Throwable $throwable, array $context = []): void
    {
        $throwableCtx = ['class' => get_class($throwable), 'message' => $throwable->getMessage(), 'stack trace' => $throwable->getTraceAsString()];
        if ($throwable instanceof GetContextInterface) {
            $throwableCtx += ['context' => $throwable->getContext()];
        }
        $updatedCtx = ['Throwable' => $throwableCtx] + $context;
        $this->with($line, $message, $updatedCtx);
    }
}
