<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Log;

use OpenTelemetry\Distro\Log\LogLevel;
use Override;

abstract class SinkBase implements SinkInterface
{
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
        if ($includeStacktrace === null ? ($statementLevel <= LogLevel::error) : $includeStacktrace) {
            $context[LoggableStackTrace::STACK_TRACE_KEY] = LoggableStackTrace::buildForCurrent($numberOfStackFramesToSkip + 1);
        }

        $this->formatAndWrite(
            levelInt: $statementLevel->value,
            levelString: strtoupper($statementLevel->name),
            category: $category,
            srcCodeFile: $srcCodeFile,
            srcCodeLine: $srcCodeLine,
            srcCodeFunc: $srcCodeFunc,
            message: $message,
            contextAsString: LoggableToString::convert($context),
        );
    }

    abstract protected function formatAndWrite(
        int $levelInt,
        string $levelString,
        string $category,
        string $srcCodeFile,
        int $srcCodeLine,
        string $srcCodeFunc,
        string $message,
        string $contextAsString,
    ): void;
}
