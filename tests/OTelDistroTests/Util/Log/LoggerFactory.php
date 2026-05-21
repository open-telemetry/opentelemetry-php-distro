<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Log;

use OpenTelemetry\Distro\Log\LogLevel;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class LoggerFactory
{
    private LogBackendForTests $backend;

    /** @var array<string, mixed> */
    public array $context;

    /**
     * @param LogBackendForTests              $backend
     * @param array<string, mixed> $context
     */
    public function __construct(LogBackendForTests $backend, array $context = [])
    {
        $this->backend = $backend;
        $this->context = $context;
    }

    /**
     * @param class-string $fqClassName
     */
    public function loggerForClass(
        string $category,
        string $namespace,
        string $fqClassName,
        string $srcCodeFile
    ): Logger {
        return Logger::makeRoot($category, $namespace, $fqClassName, $srcCodeFile, $this->context, $this->backend);
    }

    public function getBackend(): LogBackendForTests
    {
        return $this->backend;
    }

    /** @noinspection PhpUnused */
    public function isEnabledForLevel(LogLevel $level): bool
    {
        return $this->backend->isEnabledForLevel($level);
    }

    public function inherit(): self
    {
        return new self($this->backend);
    }

    public function addContext(string $key, mixed $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }

    /**
     * @param array<string, mixed> $keyValuePairs
     *
     * @return self
     *
     * @noinspection PhpUnused
     */
    public function addAllContext(array $keyValuePairs): self
    {
        foreach ($keyValuePairs as $key => $value) {
            $this->addContext($key, $value);
        }
        return $this;
    }
}
