<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\Util;

use OpenTelemetry\Distro\Log\LogLevel;

class MockLogPreformattedSinkStatement
{
    public function __construct(
        public LogLevel $level,
        public ?string $category,
        public string $file,
        public int $line,
        public string $func,
        public string $message,
        public string $contextAsString,
    ) {
    }
}
