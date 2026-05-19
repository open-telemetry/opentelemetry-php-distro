<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Log;

use OpenTelemetry\Distro\Log\LogLevel;
use Override;

/**
 * @phpstan-import-type Context from SinkInterface
 */
abstract class SinkBase implements SinkInterface
{
    /** @inheritDoc */
    #[Override]
    public function consume(
        LogLevel $level,
        string $category,
        string $file,
        int $line,
        string $func,
        string $message,
        array $context,
        ?bool $includeStacktrace,
        int $numberOfStackFramesToSkip
    ): void {
        if ($includeStacktrace === null ? ($level <= LogLevel::error) : $includeStacktrace) {
            $context[LoggableStackTrace::STACK_TRACE_KEY] = LoggableStackTrace::buildForCurrent($numberOfStackFramesToSkip + 1);
        }

        $this->formatAndWrite(level: $level, category: $category, file: $file, line: $line, func: $func, message: $message, context: $context);
    }

    /**
     * @phpstan-param Context $context
     */
    abstract protected function formatAndWrite(
        LogLevel $level,
        ?string $category,
        string $file,
        int $line,
        string $func,
        string $message,
        array $context
    ): void;
}
