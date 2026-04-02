<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro;

use Closure;
use OpenTelemetry\Distro\Log\LogLevel;
use RuntimeException;
use Throwable;
use OpenTelemetry\Distro\Util\SingletonInstanceTrait;

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
    use BootstrapStageLoggingClassTrait;
    /**
     * Constructor is hidden because instance() should be used instead
     */
    use SingletonInstanceTrait;

    /**
     * @var array<array{string, string, ?Closure, ?Closure}>
     */
    public array $delayedHooks = [];

    private bool $enableDebugHooks;

    public function bootstrap(): void
    {
        self::nativeHook(null, 'spl_autoload_register', null, $this->retryDelayedHooks(...));

        $instrumentationHookPhp = ProdPhpDir::$fullPath . DIRECTORY_SEPARATOR . 'Instrumentation' . DIRECTORY_SEPARATOR . 'hook.php';
        if (!file_exists($instrumentationHookPhp)) {
            throw new RuntimeException("File $instrumentationHookPhp does not exist");
        }

        self::logTrace(__LINE__, __FUNCTION__, 'Before require', compact('instrumentationHookPhp'));
        require $instrumentationHookPhp;

        /**
         * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
         * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
         */
        $this->enableDebugHooks = (bool)\OpenTelemetry\Distro\get_config_option_by_name('debug_php_hooks_enabled');

        self::logDebug(__LINE__, __FUNCTION__, 'Finished successfully');
    }

    /**
     * @phpstan-param PreHook  $pre
     * @phpstan-param PostHook $post
     */
    public function hook(?string $class, string $function, ?Closure $pre = null, ?Closure $post = null): bool
    {
        self::logTrace(__LINE__, __FUNCTION__, 'Entered', compact('class', 'function'));

        if ($class !== null && !self::classOrInterfaceExists($class)) {
            $this->addToDelayedHooks($class, $function, $pre, $post);
            return true;
        }

        $success = self::nativeHookNoThrow($class, $function, $pre, $post);

        if ($this->enableDebugHooks) {
            self::placeDebugHooks($class, $function);
        }

        return $success;
    }

    /**
     * @phpstan-param PreHook  $pre
     * @phpstan-param PostHook $post
     */
    private function addToDelayedHooks(string $class, string $function, ?Closure $pre = null, ?Closure $post = null): void
    {
        self::logTrace(__LINE__, __FUNCTION__, 'Adding to delayed hooks', compact('class', 'function'));

        $this->delayedHooks[] = [$class, $function, $pre, $post];
    }

    /**
     * @phpstan-param PreHook  $pre
     * @phpstan-param PostHook $post
     */
    private static function nativeHook(?string $class, string $function, ?Closure $pre = null, ?Closure $post = null): void
    {
        $dbgClassAsString = BootstrapStageLogger::nullableToLog($class);
        self::logTrace(__LINE__, __FUNCTION__, 'Entered', compact('dbgClassAsString', 'function'));

        /**
         * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
         * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
         */
        $retVal = \OpenTelemetry\Distro\hook($class, $function, $pre, $post);
        if ($retVal) {
            self::logTrace(__LINE__, __FUNCTION__, 'Successfully hooked', compact('dbgClassAsString', 'function'));
            return;
        }

        self::logDebug(__LINE__, __FUNCTION__, 'OpenTelemetry\Distro\hook returned false', compact('dbgClassAsString', 'function'));
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
            self::logCriticalThrowable(__LINE__, __FUNCTION__, $throwable, 'Call to nativeHook has thrown', compact('class', 'function'));
            return false;
        }
    }

    public function retryDelayedHooks(): void
    {
        self::logTrace(__LINE__, __FUNCTION__, 'Entered', ['delayedHooks count' => count($this->delayedHooks)]);

        if (count($this->delayedHooks) === 0) {
            return;
        }

        $delayedHooksToKeep = [];
        foreach ($this->delayedHooks as $delayedHookTuple) {
            $class = $delayedHookTuple[0];
            if (!self::classOrInterfaceExists($class)) {
                self::logTrace(__LINE__, __FUNCTION__, 'Class/Interface still does not exist - keeping delayed hook', compact('class'));
                $delayedHooksToKeep[] = $delayedHookTuple;
                continue;
            }

            self::nativeHook(...$delayedHookTuple);
        }

        $this->delayedHooks = $delayedHooksToKeep;
        self::logTrace(__LINE__, __FUNCTION__, 'Exiting', ['delayedHooks count' => count($this->delayedHooks)]);
    }

    private static function classOrInterfaceExists(string $classOrInterface): bool
    {
        return class_exists($classOrInterface) || interface_exists($classOrInterface);
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
}
