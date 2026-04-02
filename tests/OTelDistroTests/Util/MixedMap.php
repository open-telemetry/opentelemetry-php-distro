<?php

declare(strict_types=1);

namespace OTelDistroTests\Util;

use ArrayAccess;
use ArrayIterator;
use OpenTelemetry\Distro\Log\LogLevel;
use OpenTelemetry\Distro\Util\ArrayUtil;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LogStreamInterface;
use IteratorAggregate;
use Override;
use PHPUnit\Framework\Assert;
use ReturnTypeWillChange;
use Traversable;

/**
 * @implements ArrayAccess<string, mixed>
 * @implements IteratorAggregate<string, mixed>
 */
class MixedMap implements LoggableInterface, ArrayAccess, IteratorAggregate
{
    /** @var array<string, mixed> */
    private array $map;

    /**
     * @param array<string, mixed> $initialMap
     */
    public function __construct(array $initialMap = [])
    {
        $this->map = $initialMap;
    }

    /**
     * @param array<array-key, mixed> $array
     *
     * @return array<string, mixed>
     */
    public static function assertValidMixedMapArray(array $array): array
    {
        foreach ($array as $key => $ignored) {
            Assert::assertIsString($key);
        }
        /**
         * @var array<string, mixed> $array
         */
        return $array;
    }

    /**
     * @param array<array-key, mixed> $from
     */
    public static function getFrom(string $key, array $from): mixed
    {
        Assert::assertArrayHasKey($key, $from);
        return $from[$key];
    }

    public function get(string $key): mixed
    {
        return self::getFrom($key, $this->map);
    }

    /** @noinspection PhpUnused */
    public function getIfKeyExistsElse(string $key, mixed $fallbackValue): mixed
    {
        return ArrayUtil::getValueIfKeyExistsElse($key, $this->map, $fallbackValue);
    }

    /**
     * @param array<array-key, mixed> $from
     */
    public static function getNullableBoolFrom(string $key, array $from): ?bool
    {
        $value = self::getFrom($key, $from);
        if ($value !== null) {
            Assert::assertIsBool($value);
        }
        return $value;
    }

    /**
     * @param array<array-key, mixed> $from
     */
    public static function getBoolFrom(string $key, array $from): bool
    {
        return AssertEx::notNull(self::getNullableBoolFrom($key, $from));
    }

    public function getNullableBool(string $key): ?bool
    {
        return self::getNullableBoolFrom($key, $this->map);
    }

    public function getBool(string $key): bool
    {
        return self::getBoolFrom($key, $this->map);
    }

    public function tryToGetBool(string $key): ?bool
    {
        if (!array_key_exists($key, $this->map)) {
            return null;
        }
        return self::getBool($key);
    }

    /**
     * @param array<array-key, mixed> $from
     */
    public static function getNullableStringFrom(string $key, array $from): ?string
    {
        $value = self::getFrom($key, $from);
        if ($value !== null) {
            Assert::assertIsString($value);
        }
        return $value;
    }

    public function getNullableString(string $key): ?string
    {
        return self::getNullableStringFrom($key, $this->map);
    }

    /**
     * @param array<array-key, mixed> $from
     */
    public static function getStringFrom(string $key, array $from): string
    {
        return AssertEx::notNull(self::getNullableStringFrom($key, $from));
    }

    public function getString(string $key): string
    {
        return self::getStringFrom($key, $this->map);
    }

    public function getNullableFloat(string $key): ?float
    {
        $value = $this->get($key);
        if ($value === null || is_float($value)) {
            return $value;
        }
        Assert::assertIsInt($value);
        return floatval($value);
    }

    /** @noinspection PhpUnused */
    public function getFloat(string $key): float
    {
        $value = $this->getNullableFloat($key);
        return AssertEx::notNull($value);
    }

    public function getNullableInt(string $key): ?int
    {
        $value = $this->get($key);
        if ($value === null) {
            return null;
        }
        return AssertEx::isInt($value);
    }

    /**
     * @param string $key
     *
     * @return null|positive-int|0
     *
     * @noinspection PhpUnused
     */
    public function getNullablePositiveOrZeroInt(string $key): ?int
    {
        $value = $this->getNullableInt($key);
        if ($value !== null) {
            Assert::assertGreaterThanOrEqual(0, $value);
        }
        /** @var null|positive-int|0 $value */
        return $value;
    }

    public function getInt(string $key): int
    {
        return AssertEx::notNull($this->getNullableInt($key));
    }

    /**
     * @param string $key
     *
     * @return positive-int|0
     *
     * @noinspection PhpUnused
     */
    public function getPositiveOrZeroInt(string $key): int
    {
        $value = $this->getInt($key);
        Assert::assertGreaterThanOrEqual(0, $value);
        /** @var positive-int|0 $value */
        return $value;
    }

    /**
     * @return ?array<array-key, mixed>
     */
    public function getNullableArray(string $key): ?array
    {
        $value = $this->get($key);
        if ($value !== null) {
            Assert::assertIsArray($value);
        }
        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getArray(string $key): array
    {
        return AssertEx::notNull($this->getNullableArray($key));
    }

    /**
     * @template TObj of object
     *
     * @param class-string<TObj> $className
     *
     * @phpstan-return ?TObj
     */
    public function getNullableObject(string $key, string $className): ?object
    {
        $value = $this->get($key);
        if ($value === null) {
            return null;
        }
        Assert::assertInstanceOf($className, $value);
        return $value;
    }

    /**
     * @template TObj of object
     *
     * @param class-string<TObj> $className
     *
     * @phpstan-return TObj
     */
    public function getObject(string $key, string $className): object
    {
        return AssertEx::notNull($this->getNullableObject($key, $className));
    }

    public function getLogLevel(string $key): LogLevel
    {
        return $this->getObject($key, LogLevel::class);
    }

    /**
     * @return self
     */
    public function clone(): self
    {
        return new MixedMap($this->map);
    }

    /**
     * @return array<string, mixed>
     */
    public function cloneAsArray(): array
    {
        return $this->map;
    }

    /**
     * @inheritDoc
     *
     * @param string $offset
     *
     * @return bool
     */
    #[Override]
    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->map);
    }

    /**
     * @inheritDoc
     *
     * @param string $offset
     *
     * @return mixed
     */
    #[Override]
    #[ReturnTypeWillChange]
    public function offsetGet($offset): mixed
    {
        return $this->map[$offset];
    }

    /**
     * @inheritDoc
     *
     * @param string $offset
     */
    #[Override]
    public function offsetSet($offset, mixed $value): void
    {
        Assert::assertIsString($offset); /** @phpstan-ignore staticMethod.alreadyNarrowedType */
        $this->map[$offset] = $value;
    }

    /**
     * @inheritDoc
     *
     * @param string $offset
     */
    #[Override]
    public function offsetUnset($offset): void
    {
        Assert::assertArrayHasKey($offset, $this->map);
        unset($this->map[$offset]);
    }

    /**
     * @return Traversable<string, mixed>
     */
    #[Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->map);
    }

    #[Override]
    public function toLog(LogStreamInterface $stream): void
    {
        $stream->toLogAs($this->map);
    }
}
