<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\Log;

use Closure;
use OpenTelemetry\Distro\BootstrapStageLoggingClassTrait;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;

final class SplAutoloadFunctionsLogUtil
{
    use BootstrapStageLoggingClassTrait;

    /**
     * @param array<callable> $callbacks
     *
     * @return array<array<string, mixed>>
     */
    public static function callbacksToLoggable(array $callbacks): array
    {
        return array_map(fn($callback) => self::callbackToLoggable($callback), $callbacks);
    }

    /**
     * @return array<string, mixed>
     */
    public static function callbackToLoggable(mixed $callback): array
    {
        if ($callback instanceof Closure) {
            $reflFunc = new ReflectionFunction($callback);
            $dbgDesc = 'Closure at ' . ($reflFunc->getFileName() . ':' . $reflFunc->getStartLine());
            return array_merge(compact('dbgDesc'), ['type' => get_debug_type($callback), 'source code' => ($reflFunc->getFileName() . ':' . $reflFunc->getStartLine())]);
        }

        if (is_array($callback)) {
            return self::callbackArrayToLoggable($callback);
        }

        return self::callbackStandaloneFunctionToLoggable($callback);
    }

    /**
     * @param array<mixed> $callback
     *
     * @return array<string, mixed>
     */
    private static function callbackArrayToLoggable(array $callback): array
    {
        if (count($callback) === 1) {
            return ['type' => get_debug_type($callback), 'count' => count($callback), 'values' => [self::callbackStandaloneFunctionToLoggable($callback[0])]];
        }
        if (count($callback) !== 2) {
            return ['type' => get_debug_type($callback), 'count' => count($callback)];
        }

        $values = [];
        $reflClass = null;
        $values[0] = self::callbackArray1stElementToLoggable($callback[0], /* out */ $reflClass);
        $reflMethod = null;
        $values[1] = self::callbackArray2ndElementToLoggable($callback[1], $reflClass, /* out */ $reflMethod);
        $dbgDescArr = [];
        if ($reflClass !== null && $reflMethod !== null) {
            $dbgDescArr = [
                'dbgDesc' => ($reflClass->getName() . (is_object($callback[0]) ? '->' : '::') . $reflMethod->getName() . ' at ' . $reflMethod->getFileName() . ':' . $reflClass->getStartLine())
            ];
        }

        return array_merge($dbgDescArr, ['type' => get_debug_type($callback), 'count' => count($callback), 'values' => $values]);
    }

    /**
     * @param array<string, mixed> $base
     * @param ReflectionClass<object> $reflClass
     *
     * @return array<string, mixed>
     */
    private static function addClassSourceCodeInfoToLoggable(array $base, ReflectionClass $reflClass): array
    {
        return array_merge($base, ['source code' => ($reflClass->getFileName() . ':' . $reflClass->getStartLine())]);
    }

    /**
     * @param ?ReflectionClass<object> &$reflClass
     *
     * @return array<string, mixed>
     */
    private static function callbackArray1stElementToLoggable(mixed $callback1stElement, /* out */ ?ReflectionClass &$reflClass): array
    {
        if (is_object($callback1stElement)) {
            $reflClass = new ReflectionClass($callback1stElement);
            return self::addClassSourceCodeInfoToLoggable(['type' => get_debug_type($callback1stElement), 'object ID' => spl_object_id($callback1stElement)], $reflClass);
        }

        if (is_string($callback1stElement) && class_exists($callback1stElement, autoload: false)) {
            $reflClass = new ReflectionClass($callback1stElement);
            return self::addClassSourceCodeInfoToLoggable(['type' => get_debug_type($callback1stElement), 'value' => $callback1stElement], $reflClass);
        }

        return ['type' => get_debug_type($callback1stElement)];
    }

    /**
     * @param ?ReflectionClass<object> $reflClass
     *
     * @return array<string, mixed>
     */
    private static function callbackArray2ndElementToLoggable(mixed $callback2ndElement, ?ReflectionClass $reflClass, /* out */ ?ReflectionMethod &$reflMethod): array
    {
        if (($reflClass !== null) && is_string($callback2ndElement)) {
            $reflMethod = $reflClass->getMethod($callback2ndElement);
            return ['method' => $callback2ndElement, 'source code' => ($reflMethod->getFileName() . ':' . $reflMethod->getStartLine())];
        }

        return self::callbackStandaloneFunctionToLoggable($callback2ndElement);
    }

    /**
     * @return array<string, mixed>
     */
    private static function callbackStandaloneFunctionToLoggable(mixed $callback): array
    {
        if (is_string($callback) && function_exists($callback)) {
            $reflFunc = new ReflectionFunction($callback);
            return ['type' => get_debug_type($callback), 'source code' => ($reflFunc->getFileName() . ':' . $reflFunc->getStartLine())];
        }

        if (is_scalar($callback) || ($callback === null)) {
            return ['type' => get_debug_type($callback), 'value' => $callback];
        }

        return ['type' => get_debug_type($callback)];
    }

    /**
     * Must be defined in class using BootstrapStageLoggingClassTrait
     */
    private static function getCurrentSourceCodeFile(): string
    {
        return __FILE__;
    }

    /**
     * Must be defined in class using BootstrapStageLoggingClassTrait
     */
    private static function getCurrentSourceCodeClass(): string
    {
        return __CLASS__;
    }

    /**
     * Must be defined in class using BootstrapStageLoggingClassTrait
     */
    private static function getCurrentLogFeature(): int
    {
        return LogFeature::MODULE;
    }
}
