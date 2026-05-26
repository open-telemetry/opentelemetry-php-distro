<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro;

use Closure;
use OpenTelemetry\Distro\Log\LoggingClassTrait;
use OpenTelemetry\Distro\Log\LogFeature;
use OpenTelemetry\Distro\Log\LogLevel;
use OpenTelemetry\Distro\Util\DistroRuntimeException;
use OpenTelemetry\Distro\Util\SingletonInstanceTrait;
use Throwable;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 *
 * @phpstan-type PreHook Closure(?object $thisObj, array<mixed> $params, string $class, string $function, ?string $filename, ?int $lineno): (void|array<mixed>)
 *                  return value is modified parameters
 *
 * @phpstan-type PostHook Closure(?object $thisObj, array<mixed> $params, mixed $returnValue, ?Throwable $throwable): mixed
 *                  return value is modified return value
 */
final class InstrumentationBridge
{
    use LoggingClassTrait;
    /**
     * Constructor is hidden because instance() should be used instead
     */
    use SingletonInstanceTrait;

    private bool $enableDebugHooks;

    public function bootstrap(): void
    {
        $logDebug = self::logTrace(__FUNCTION__);
        $logDebug?->with(__LINE__, 'Entered');

        $instrumentationHookPhp = ProdPhpDir::$fullPath . DIRECTORY_SEPARATOR . 'OpenTelemetry' . DIRECTORY_SEPARATOR . 'Instrumentation' . DIRECTORY_SEPARATOR . 'hook.php';
        if (!file_exists($instrumentationHookPhp)) {
            throw new DistroRuntimeException("File $instrumentationHookPhp does not exist");
        }

        $logDebug?->with(__LINE__, 'Before require ' . $instrumentationHookPhp);
        require $instrumentationHookPhp;
        $logDebug?->with(__LINE__, 'After require ' . $instrumentationHookPhp);

        /**
         * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
         * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
         */
        $this->enableDebugHooks = (bool)\OpenTelemetry\Distro\get_config_option_by_name('debug_php_hooks_enabled');

        $logDebug?->with(__LINE__, 'Exiting');
    }

    /**
     * @phpstan-param PreHook  $pre
     * @phpstan-param PostHook $post
     */
    public function hook(?string $class, string $function, ?Closure $pre = null, ?Closure $post = null): bool
    {
        $logTrace = self::logTrace(__FUNCTION__);
        $logTrace?->with(__LINE__, 'Entered', compact('class', 'function'));

        if ($class !== null) {
            class_exists($class) || interface_exists($class);
        }

        $success = self::nativeHookNoThrow($class, $function, $pre, $post);

        if ($this->enableDebugHooks) {
            self::placeDebugHooks($class, $function);
        }

        $logTrace?->with(__LINE__, 'Exiting', compact('success', 'class', 'function'));
        return $success;
    }

    private static function nullableToLog(null|int|string $str): string
    {
        return $str === null ? 'null' : strval($str);
    }

    /**
     * @phpstan-param PreHook  $pre
     * @phpstan-param PostHook $post
     */
    private static function nativeHook(?string $class, string $function, ?Closure $pre = null, ?Closure $post = null): void
    {
        $logTrace = self::logTrace(__FUNCTION__);
        $dbgClassAsString = self::nullableToLog($class);
        $logTrace?->with(__LINE__, 'Entered', compact('dbgClassAsString', 'function'));

        /**
         * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
         * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
         */
        $retVal = \OpenTelemetry\Distro\hook($class, $function, $pre, $post);
        if ($retVal) {
            $logTrace?->with(__LINE__, 'Successfully hooked', compact('dbgClassAsString', 'function'));
            return;
        }

        self::logDebug(__FUNCTION__)?->with(__LINE__, 'OpenTelemetry\Distro\hook returned false', compact('dbgClassAsString', 'function'));
    }

    /**
     * @phpstan-param PreHook  $pre
     * @phpstan-param PostHook $post
     */
    private static function nativeHookNoThrow(?string $class, string $function, ?Closure $pre = null, ?Closure $post = null): bool
    {
        try {
            self::nativeHook($class, $function, $pre, $post);
            return true;
        } catch (Throwable $throwable) {
            self::logCritical(__FUNCTION__)?->withThrowable(__LINE__, 'Call to nativeHook has thrown', $throwable, compact('class', 'function'));
            return false;
        }
    }

    private static function placeDebugHooks(?string $class, string $function): void
    {
        $func = '\'';
        if ($class) {
            $func = $class . '::';
        }
        $func .= $function . '\'';

        self::nativeHookNoThrow(
            $class,
            $function,
            function () use ($func) {
                /**
                 * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
                 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
                 */
                \OpenTelemetry\Distro\log_feature(
                    0 /* <- isForced */,
                    LogLevel::debug->value,
                    Log\LogFeature::INSTRUMENTATION,
                    '' /* <- file */,
                    null /* <- line */,
                    $func,
                    ('pre-hook data: ' . var_export(func_get_args(), true))
                );
            },
            function () use ($func) {
                /**
                 * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
                 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
                 */
                \OpenTelemetry\Distro\log_feature(
                    0 /* <- isForced */,
                    LogLevel::debug->value,
                    Log\LogFeature::INSTRUMENTATION,
                    '' /* <- file */,
                    null /* <- line */,
                    $func,
                    ('post-hook data: ' . var_export(func_get_args(), true))
                );
            }
        );
    }

    /**
     * Must be defined in class using LoggingClassTrait
     */
    private static function getCurrentSourceCodeFile(): string
    {
        return __FILE__;
    }

    /**
     * Must be defined in class using LoggingClassTrait
     */
    private static function getCurrentOptionalLogProdFeatureIntOrCategoryString(): int
    {
        return LogFeature::INSTRUMENTATION;
    }
}
