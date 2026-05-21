<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Log;

use OpenTelemetry\Distro\Log\LogLevel;
use Throwable;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class EnabledTestLogProxy
{
    private ?bool $includeStackTrace = null;

    public function __construct(
        private readonly LogLevel $statementLevel,
        private readonly string $srcCodeFunc,
        private readonly LoggerData $loggerData
    ) {
    }

    /** @noinspection PhpUnused */
    public function includeStackTrace(bool $shouldIncludeStackTrace = true): self
    {
        $this->includeStackTrace = $shouldIncludeStackTrace;
        return $this;
    }

    /**
     * @param array<string, mixed> $statementCtx
     */
    public function with(int $srcCodeLine, string $message, array $statementCtx = []): bool
    {
        $this->loggerData->backend->log(
            $this->statementLevel,
            $message,
            $statementCtx,
            $srcCodeLine,
            $this->srcCodeFunc,
            $this->loggerData,
            $this->includeStackTrace,
            numberOfStackFramesToSkip: 1,
        );
        // return dummy bool to suppress PHPStan's "Right side of && is always false"
        return true;
    }

    /**
     * @param array<string, mixed> $statementCtx
     */
    public function withThrowable(int $srcCodeLine, string $message, Throwable $throwable, array $statementCtx = []): bool
    {
        $this->loggerData->backend->log(
            $this->statementLevel,
            $message,
            $statementCtx + ['throwable' => $throwable],
            $srcCodeLine,
            $this->srcCodeFunc,
            $this->loggerData,
            $this->includeStackTrace,
            numberOfStackFramesToSkip: 1
        );
        // return dummy bool to suppress PHPStan's "Right side of && is always false"
        return true;
    }
}
