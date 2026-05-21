<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\Util;

use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\Log\SinkBase;
use Override;

/**
 * @phpstan-import-type Context from SinkBase
 */
class MockLogPreformattedSink extends SinkBase
{
    /** @var MockLogPreformattedSinkStatement[] */
    public array $consumed = [];

    /** @inheritDoc */
    #[Override]
    protected function formatAndWrite(LogLevel $level, ?string $category, string $file, int $line, string $func, string $message, array $context): void
    {
        $this->consumed[] = new MockLogPreformattedSinkStatement(
            level: $level,
            category: $category,
            file: $file,
            line: $line,
            func: $func,
            message: $message,
            contextAsString: LoggableToString::convert($context)
        );
    }
}
