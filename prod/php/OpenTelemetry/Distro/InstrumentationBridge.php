<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro;

use Closure;
use OpenTelemetry\Distro\Log\LogLevel;
use Throwable;
use OpenTelemetry\Distro\Util\SingletonInstanceTrait;

use function OpenTelemetry\Distro\get_config_option_by_name;
use function OpenTelemetry\Distro\hook;
use function OpenTelemetry\Distro\log_feature;

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

        require ProdPhpDir::$fullPath . DIRECTORY_SEPARATOR . 'OpenTelemetry' . DIRECTORY_SEPARATOR . 'Instrumentation' . DIRECTORY_SEPARATOR . 'hook.php';

        $this->enableDebugHooks = (bool)get_config_option_by_name('debug_php_hooks_enabled');

        BootstrapStageLogger::logDebug('Finished successfully', __FILE__, __LINE__, __CLASS__, __FUNCTION__);
    }

    /**
     * @phpstan-param PreHook  $pre
     * @phpstan-param PostHook $post
     */
    public function hook(?string $class, string $function, ?Closure $pre = null, ?Closure $post = null): bool
    {
        BootstrapStageLogger::logTrace('Entered. class: ' . $class .  ' function: ' . $function, __FILE__, __LINE__, __CLASS__, __FUNCTION__);

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
        BootstrapStageLogger::logTrace('Adding to delayed hooks. class: ' . $class . ', function: ' . $function, __FILE__, __LINE__, __CLASS__, __FUNCTION__);

        $this->delayedHooks[] = [$class, $function, $pre, $post];
    }

    /**
     * @phpstan-param PreHook  $pre
     * @phpstan-param PostHook $post
     */
    private static function nativeHook(?string $class, string $function, ?Closure $pre = null, ?Closure $post = null): void
    {
        $dbgClassAsString = BootstrapStageLogger::nullableToLog($class);
        BootstrapStageLogger::logTrace('Entered. class: ' . $dbgClassAsString . ', function: ' . $function, __FILE__, __LINE__, __CLASS__, __FUNCTION__);

        // OpenTelemetry\Distro\hook function is provided by the extension
        $retVal = hook($class, $function, $pre, $post);
        if ($retVal) {
            BootstrapStageLogger::logTrace('Successfully hooked. class: ' . $dbgClassAsString . ', function: ' . $function, __FILE__, __LINE__, __CLASS__, __FUNCTION__);
            return;
        }

        BootstrapStageLogger::logDebug('OpenTelemetry\Distro\hook returned false: ' . $dbgClassAsString . ', function: ' . $function, __FILE__, __LINE__, __CLASS__, __FUNCTION__);
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
            BootstrapStageLogger::logCriticalThrowable($throwable, 'Call to nativeHook has thrown', __FILE__, __LINE__, __CLASS__, __FUNCTION__);
            return false;
        }
    }

    public function retryDelayedHooks(): void
    {
        $delayedHooksCount = count($this->delayedHooks);
        BootstrapStageLogger::logTrace('Entered. delayedHooks count: ' . $delayedHooksCount, __FILE__, __LINE__, __CLASS__, __FUNCTION__);

        if (count($this->delayedHooks) === 0) {
            return;
        }

        $delayedHooksToKeep = [];
        foreach ($this->delayedHooks as $delayedHookTuple) {
            $class = $delayedHookTuple[0];
            if (!self::classOrInterfaceExists($class)) {
                BootstrapStageLogger::logTrace('Class/Interface still does not exist - keeping delayed hook. class: ' . $class, __FILE__, __LINE__, __CLASS__, __FUNCTION__);
                $delayedHooksToKeep[] = $delayedHookTuple;
                continue;
            }

            self::nativeHook(...$delayedHookTuple);
        }

        $this->delayedHooks = $delayedHooksToKeep;
        BootstrapStageLogger::logTrace('Exiting... delayedHooks count: ' . count($this->delayedHooks), __FILE__, __LINE__, __CLASS__, __FUNCTION__);
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
                log_feature(
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
                log_feature(
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
}
