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

    public function logCritical(string $srcCodeFunc): ?EnabledTestLogProxy
    {
        return $this->logWithLevel($srcCodeFunc, LogLevel::critical);
    }

    public function logError(string $srcCodeFunc): ?EnabledTestLogProxy
    {
        return $this->logWithLevel($srcCodeFunc, LogLevel::error);
    }

    public function logWarning(string $srcCodeFunc): ?EnabledTestLogProxy
    {
        return $this->logWithLevel($srcCodeFunc, LogLevel::warning);
    }

    public function logInfo(string $srcCodeFunc): ?EnabledTestLogProxy
    {
        return $this->logWithLevel($srcCodeFunc, LogLevel::info);
    }

    public function logDebug(string $srcCodeFunc): ?EnabledTestLogProxy
    {
        return $this->logWithLevel($srcCodeFunc, LogLevel::debug);
    }

    public function logTrace(string $srcCodeFunc): ?EnabledTestLogProxy
    {
        return $this->logWithLevel($srcCodeFunc, LogLevel::trace);
    }

    public function logWithLevel(string $srcCodeFunc, LogLevel $statementLevel): ?EnabledTestLogProxy
    {
        return ($this->data->backend->isEnabledForLevel($statementLevel))
            ? new EnabledTestLogProxy($statementLevel, $srcCodeFunc, $this->data)
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
