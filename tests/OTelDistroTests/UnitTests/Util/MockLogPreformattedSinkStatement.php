<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\Util;

use OpenTelemetry\Distro\Log\LogLevel;

class MockLogPreformattedSinkStatement
{
    public function __construct(
        public LogLevel $statementLevel,
        public string $category,
        public string $srcCodeFile,
        public int $srcCodeLine,
        public string $srcCodeFunc,
        public string $message,
        public string $contextAsString,
    ) {
    }
}
