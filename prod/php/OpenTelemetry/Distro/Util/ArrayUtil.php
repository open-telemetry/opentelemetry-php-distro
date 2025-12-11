<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\Util;

final class ArrayUtil
{
    use StaticClassTrait;

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @phpstan-param TKey                $key
     * @phpstan-param array<TKey, TValue> $array
     *
     * @param-out TValue                  $valueOut
     *
     * @phpstan-assert-if-true TValue     $valueOut
     */
    public static function getValueIfKeyExists(int|string $key, array $array, /* out */ mixed &$valueOut): bool
    {
        if (!array_key_exists($key, $array)) {
            return false;
        }

        $valueOut = $array[$key];
        return true;
    }

    /**
     * @template TKey of array-key
     * @template TArrayValue
     * @template TFallbackValue
     *
     * @phpstan-param TKey                     $key
     * @phpstan-param array<TKey, TArrayValue> $array
     * @phpstan-param TFallbackValue           $fallbackValue
     *
     * @return TArrayValue|TFallbackValue
     */
    public static function getValueIfKeyExistsElse(string|int $key, array $array, mixed $fallbackValue): mixed
    {
        return array_key_exists($key, $array) ? $array[$key] : $fallbackValue;
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @phpstan-param TKey                $key
     * @phpstan-param array<TKey, TValue> $array
     *
     * @param-out TValue                  $valueOut
     *
     * @phpstan-assert-if-true TValue     $valueOut
     */
    public static function removeValue(int|string $key, array $array, /* out */ mixed &$valueOut): bool
    {
        if (!array_key_exists($key, $array)) {
            return false;
        }

        $valueOut = $array[$key];
        unset($array[$key]);
        return true;
    }
}
