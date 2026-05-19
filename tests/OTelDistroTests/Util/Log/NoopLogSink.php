<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Log;

use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\Util\NoopObjectTrait;
use Override;

final class NoopLogSink implements SinkInterface, LoggableInterface
{
    use NoopObjectTrait;

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
    }
}
