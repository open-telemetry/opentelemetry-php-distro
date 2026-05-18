<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Log;

use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\Util\NoopObjectTrait;
use Override;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class NoopLogSink implements SinkInterface, LoggableInterface
{
    use NoopObjectTrait;

    /** @inheritDoc */
    #[Override]
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
    ): void {
    }
}
