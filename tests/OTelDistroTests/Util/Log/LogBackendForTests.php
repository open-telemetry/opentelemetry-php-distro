<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Log;

use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\ClassNameUtil;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class LogBackendForTests implements LoggableInterface
{
    public const NAMESPACE_KEY = 'namespace';
    public const CLASS_KEY = 'class';

    private LogLevel $maxEnabledLevel;

    private SinkInterface $logSink;

    public function __construct(LogLevel $maxEnabledLevel, ?SinkInterface $logSink)
    {
        $this->maxEnabledLevel = $maxEnabledLevel;
        $this->logSink = $logSink ?? NoopLogSink::singletonInstance();
    }

    public function isEnabledForLevel(LogLevel $level): bool
    {
        return $this->maxEnabledLevel->value >= $level->value;
    }

    public function clone(): self
    {
        return new self($this->maxEnabledLevel, $this->logSink);
    }

    public function setMaxEnabledLevel(LogLevel $maxEnabledLevel): void
    {
        $this->maxEnabledLevel = $maxEnabledLevel;
    }

    /**
     * @param array<array-key, mixed> $statementCtx
     *
     * @return array<array-key, mixed>
     */
    private static function mergeContexts(LoggerData $loggerData, array $statementCtx): array
    {
        /**
         * @see Comment in \OTelDistroTests\Util\Log\Logger::addAllContext regarding the order of entries in logger context
         */

        $result = $statementCtx;

        $mergeKeyValueToResult = function (string|int $key, mixed $value) use (&$result): void {
            if (!array_key_exists($key, $result)) {
                $result[$key] = $value;
            }
        };

        for (
            $currentLoggerData = $loggerData;
            $currentLoggerData !== null;
            $currentLoggerData = $currentLoggerData->inheritedData
        ) {
            foreach (ArrayUtilForTests::iterateMapInReverse($currentLoggerData->context) as $key => $value) {
                $mergeKeyValueToResult($key, $value);
            }
        }

        $mergeKeyValueToResult(self::NAMESPACE_KEY, $loggerData->namespace);
        $mergeKeyValueToResult(self::CLASS_KEY, ClassNameUtil::fqToShort($loggerData->fqClassName));

        return $result;
    }

    /**
     * @param array<string, mixed> $statementCtx
     * @param non-negative-int     $numberOfStackFramesToSkip
     */
    public function log(
        LogLevel $level,
        string $message,
        array $statementCtx,
        int $srcCodeLine,
        string $srcCodeFunc,
        LoggerData $loggerData,
        ?bool $includeStacktrace,
        int $numberOfStackFramesToSkip
    ): void {
        $this->logSink->consume(
            level: $level,
            category: $loggerData->category,
            file: $loggerData->srcCodeFile,
            line: $srcCodeLine,
            func: $srcCodeFunc,
            message: $message,
            context: self::mergeContexts($loggerData, $statementCtx),
            includeStacktrace: $includeStacktrace,
            numberOfStackFramesToSkip: $numberOfStackFramesToSkip + 1
        );
    }

    /** @noinspection PhpUnused */
    public function getSink(): SinkInterface
    {
        return $this->logSink;
    }

    public function toLog(LogStreamInterface $stream): void
    {
        $stream->toLogAs(['maxEnabledLevel' => $this->maxEnabledLevel, 'logSink' => get_debug_type($this->logSink)]);
    }
}
