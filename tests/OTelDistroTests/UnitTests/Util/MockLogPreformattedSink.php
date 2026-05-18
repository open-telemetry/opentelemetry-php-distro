<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\Util;

use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\Util\Log\SinkBase;
use Override;

class MockLogPreformattedSink extends SinkBase
{
    /** @var MockLogPreformattedSinkStatement[] */
    public array $consumed = [];

    #[Override]
    protected function formatAndWrite(
        int $levelInt,
        string $levelString,
        string $category,
        string $srcCodeFile,
        int $srcCodeLine,
        string $srcCodeFunc,
        string $message,
        string $contextAsString,
    ): void {
        $this->consumed[] = new MockLogPreformattedSinkStatement(
            statementLevel: LogLevel::from($levelInt),
            category: $category,
            srcCodeFile: $srcCodeFile,
            srcCodeLine: $srcCodeLine,
            srcCodeFunc: $srcCodeFunc,
            message: $message,
            contextAsString: $contextAsString,
        );
    }
}
