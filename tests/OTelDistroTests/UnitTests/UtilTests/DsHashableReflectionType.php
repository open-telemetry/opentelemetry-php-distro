<?php

/** @noinspection PhpMissingReturnTypeInspection */

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests;

use Ds\Hashable;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LogStreamInterface;
use OTelDistroTests\Util\ReflectionUtil;
use Override;
use ReflectionNamedType;
use ReflectionType;
use Stringable;

final class DsHashableReflectionType implements Hashable, LoggableInterface, Stringable
{
    public readonly string $canonicalName;

    public function __construct(
        public readonly ReflectionType $wrapped,
    ) {
        $this->canonicalName = ReflectionUtil::getReflectionTypeCanonicalName($wrapped);
    }

    /**
     * @param class-string<mixed> $className
     */
    public function isOrSubClassOf(string $className): bool
    {
        return
            ($this->wrapped instanceof ReflectionNamedType)
            && (class_exists($this->wrapped->getName()) || interface_exists($this->wrapped->getName()))
            && (($this->wrapped->getName() === $className) || is_subclass_of($this->wrapped->getName(), $className));
    }

    #[Override]
    public function __toString(): string
    {
        return $this->canonicalName;
    }

    #[Override]
    public function hash(): string
    {
        return $this->canonicalName;
    }

    #[Override]
    public function equals(mixed $obj): bool
    {
        return ($obj instanceof self) && ($this->canonicalName === $obj->canonicalName);
    }

    public function toLog(LogStreamInterface $stream): void
    {
        $stream->toLogAs(['canonicalName' => $this->canonicalName, 'wrapped' => $this->wrapped]);
    }
}
