<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Log;

use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\Util\ArrayUtilForTests;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class Logger implements LoggableInterface
{
    private function __construct(
        private readonly LoggerData $data
    ) {
    }

    /**
     * @param class-string         $fqClassName
     * @param array<string, mixed> $context
     *
     * @return static
     */
    public static function makeRoot(
        string $category,
        string $namespace,
        string $fqClassName,
        string $srcCodeFile,
        array $context,
        LogBackendForTests $backend
    ): self {
        return new self(LoggerData::makeRoot($category, $namespace, $fqClassName, $srcCodeFile, $context, $backend));
    }

    public function inherit(): self
    {
        return new self($this->data->inherit());
    }

    public function addContext(string $key, mixed $value): self
    {
        $this->data->context[$key] = $value;
        return $this;
    }

    /**
     * @param array<string, mixed> $keyValuePairs
     *
     * @return $this
     */
    public function addAllContext(array $keyValuePairs): self
    {
        // Entries in the context are kept in order of increasing importance.
        // More recently entry is considered more important.
        // When a batch of entries is added using addAllContext
        // we consider the first entry in the batch the most important
        // so that is why the batch is iterated in the reverse order
        foreach (ArrayUtilForTests::iterateMapInReverse($keyValuePairs) as $key => $value) {
            $this->addContext($key, $value);
        }
        return $this;
    }

    /**
     * @return array<string, mixed>
     *
     * @noinspection PhpUnused
     */
    public function getContext(): array
    {
        return $this->data->context;
    }

    public function ifCriticalLevelEnabled(int $srcCodeLine, string $srcCodeFunc): ?EnabledLoggerProxy
    {
        return $this->ifLevelEnabled(LogLevel::critical, $srcCodeLine, $srcCodeFunc);
    }

    public function ifErrorLevelEnabled(int $srcCodeLine, string $srcCodeFunc): ?EnabledLoggerProxy
    {
        return $this->ifLevelEnabled(LogLevel::error, $srcCodeLine, $srcCodeFunc);
    }

    public function ifWarningLevelEnabled(int $srcCodeLine, string $srcCodeFunc): ?EnabledLoggerProxy
    {
        return $this->ifLevelEnabled(LogLevel::warning, $srcCodeLine, $srcCodeFunc);
    }

    /** @noinspection PhpUnused */
    public function ifInfoLevelEnabled(int $srcCodeLine, string $srcCodeFunc): ?EnabledLoggerProxy
    {
        return $this->ifLevelEnabled(LogLevel::info, $srcCodeLine, $srcCodeFunc);
    }

    public function ifDebugLevelEnabled(int $srcCodeLine, string $srcCodeFunc): ?EnabledLoggerProxy
    {
        return $this->ifLevelEnabled(LogLevel::debug, $srcCodeLine, $srcCodeFunc);
    }

    public function ifTraceLevelEnabled(int $srcCodeLine, string $srcCodeFunc): ?EnabledLoggerProxy
    {
        return $this->ifLevelEnabled(LogLevel::trace, $srcCodeLine, $srcCodeFunc);
    }

    /** @noinspection PhpUnused */
    public function ifCriticalLevelEnabledNoLine(string $srcCodeFunc): ?EnabledLoggerProxyNoLine
    {
        return $this->ifLevelEnabledNoLine(LogLevel::critical, $srcCodeFunc);
    }

    /** @noinspection PhpUnused */
    public function ifErrorLevelEnabledNoLine(string $srcCodeFunc): ?EnabledLoggerProxyNoLine
    {
        return $this->ifLevelEnabledNoLine(LogLevel::error, $srcCodeFunc);
    }

    /** @noinspection PhpUnused */
    public function ifWarningLevelEnabledNoLine(string $srcCodeFunc): ?EnabledLoggerProxyNoLine
    {
        return $this->ifLevelEnabledNoLine(LogLevel::warning, $srcCodeFunc);
    }

    /** @noinspection PhpUnused */
    public function ifInfoLevelEnabledNoLine(string $srcCodeFunc): ?EnabledLoggerProxyNoLine
    {
        return $this->ifLevelEnabledNoLine(LogLevel::info, $srcCodeFunc);
    }

    /** @noinspection PhpUnused */
    public function ifDebugLevelEnabledNoLine(string $srcCodeFunc): ?EnabledLoggerProxyNoLine
    {
        return $this->ifLevelEnabledNoLine(LogLevel::debug, $srcCodeFunc);
    }

    /** @noinspection PhpUnused */
    public function ifTraceLevelEnabledNoLine(string $srcCodeFunc): ?EnabledLoggerProxyNoLine
    {
        return $this->ifLevelEnabledNoLine(LogLevel::trace, $srcCodeFunc);
    }

    public function ifLevelEnabled(LogLevel $statementLevel, int $srcCodeLine, string $srcCodeFunc): ?EnabledLoggerProxy
    {
        return ($this->data->backend->isEnabledForLevel($statementLevel))
            ? new EnabledLoggerProxy($statementLevel, $srcCodeLine, $srcCodeFunc, $this->data)
            : null;
    }

    public function ifLevelEnabledNoLine(LogLevel $statementLevel, string $srcCodeFunc): ?EnabledLoggerProxyNoLine
    {
        return ($this->data->backend->isEnabledForLevel($statementLevel))
            ? new EnabledLoggerProxyNoLine($statementLevel, $srcCodeFunc, $this->data)
            : null;
    }

    public function isEnabledForLevel(LogLevel $level): bool
    {
        return $this->data->backend->isEnabledForLevel($level);
    }

    public function isTraceLevelEnabled(): bool
    {
        return $this->isEnabledForLevel(LogLevel::trace);
    }

    /** @noinspection PhpUnused */
    public function possiblySecuritySensitive(mixed $value): mixed
    {
        if ($this->isTraceLevelEnabled()) {
            return $value;
        }
        return 'REDACTED (POSSIBLY SECURITY SENSITIVE) DATA';
    }

    public function toLog(LogStreamInterface $stream): void
    {
        $stream->toLogAs($this->data);
    }
}
