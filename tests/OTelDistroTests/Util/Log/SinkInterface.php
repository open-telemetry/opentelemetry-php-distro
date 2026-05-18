<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Log;

use OpenTelemetry\Distro\Log\LogLevel;

interface SinkInterface
{
    /**
     * @param array<array-key, mixed> $context
     * @param non-negative-int        $numberOfStackFramesToSkip
     */
    public function consume(
        LogLevel $statementLevel,
        string $category,
        string $srcCodeFile,
        int $srcCodeLine,
        string $srcCodeFunc,
        string $message,
        array $context,
        ?bool $includeStacktrace,
        int $numberOfStackFramesToSkip
    ): void;
}
