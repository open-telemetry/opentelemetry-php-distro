<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Log;

use OpenTelemetry\Distro\Log\LogBackend;
use OpenTelemetry\Distro\Log\LogLevel;

/**
 * @phpstan-import-type Context from LogBackend
 */
interface SinkInterface
{
    /**
     * @phpstan-param Context $context
     * @param non-negative-int $numberOfStackFramesToSkip
     */
    public function consume(
        LogLevel $level,
        string $category,
        string $file,
        int $line,
        string $func,
        string $message,
        array $context,
        ?bool $includeStacktrace,
        int $numberOfStackFramesToSkip,
    ): void;
}
