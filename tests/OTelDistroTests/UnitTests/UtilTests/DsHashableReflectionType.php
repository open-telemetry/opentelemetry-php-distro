<?php

/** @noinspection PhpMissingReturnTypeInspection */

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests;

use Ds\Hashable;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LogStreamInterface;
use Override;
use ReflectionType;

class DsHashableReflectionType implements Hashable, LoggableInterface
{
    public function __construct(
        public readonly ReflectionType $wrapped,
    ) {
    }

    public function __toString(): string
    {
        return $this->wrapped->__toString();
    }

    #[Override]
    public function hash(): string
    {
        return $this->wrapped->__toString();
    }


    #[Override]
    public function equals(mixed $obj): bool
    {
        return
            ($obj instanceof self)
                ? ($this->wrapped->__toString() === $obj->wrapped->__toString())
                : (($obj instanceof ReflectionType) && ($this->wrapped->__toString() === $obj->__toString()));
    }

    public function toLog(LogStreamInterface $stream): void
    {
        $stream->toLogAs($this->wrapped);
    }
}
