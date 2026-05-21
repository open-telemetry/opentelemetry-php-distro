<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Log;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class LoggerData implements LoggableInterface
{
    public string $category;
    public string $namespace;
    /** @var class-string */
    public string $fqClassName;
    public string $srcCodeFile;
    public ?LoggerData $inheritedData;

    /** @var array<string, mixed> */
    public array $context;

    public LogBackendForTests $backend;

    /**
     * @param class-string         $fqClassName
     * @param array<string, mixed> $context
     */
    private function __construct(
        string $category,
        string $namespace,
        string $fqClassName,
        string $srcCodeFile,
        array $context,
        LogBackendForTests $backend,
        ?LoggerData $inheritedData
    ) {
        $this->category = $category;
        $this->namespace = $namespace;
        $this->fqClassName = $fqClassName;
        $this->srcCodeFile = $srcCodeFile;
        $this->context = $context;
        $this->backend = $backend;
        $this->inheritedData = $inheritedData;
    }

    /**
     * @param class-string         $fqClassName
     * @param array<string, mixed> $context
     *
     * @return self
     */
    public static function makeRoot(
        string $category,
        string $namespace,
        string $fqClassName,
        string $srcCodeFile,
        array $context,
        LogBackendForTests $backend
    ): self {
        return new self(
            $category,
            $namespace,
            $fqClassName,
            $srcCodeFile,
            $context,
            $backend,
            /* inheritedData */ null
        );
    }

    public function inherit(): self
    {
        return new self(
            $this->category,
            $this->namespace,
            $this->fqClassName,
            $this->srcCodeFile,
            [] /* <- context */,
            $this->backend,
            $this
        );
    }

    public function toLog(LogStreamInterface $stream): void
    {
        $stream->toLogAs(
            [
                'category'       => $this->category,
                'namespace'      => $this->namespace,
                'fqClassName'    => $this->fqClassName,
                'srcCodeFile'    => $this->srcCodeFile,
                'inheritedData'  => $this->inheritedData,
                'count(context)' => count($this->context),
                'backend'        => $this->backend,
            ]
        );
    }
}
