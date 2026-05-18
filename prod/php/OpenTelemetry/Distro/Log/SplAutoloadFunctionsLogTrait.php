<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\Log;

trait SplAutoloadFunctionsLogTrait
{
    // This trait assumes that BootstrapStageLoggingClassTrait is used by the class

    private static function logAutoloadFunctions(int $logLevel, int $line, string $func, string $message): void
    {
        if (self::isLogEnabledForLevel($logLevel)) {
            self::logWithLevel($logLevel, $line, $func, $message, ['spl_autoload_functions()' => SplAutoloadFunctionsLogUtil::callbacksToLoggable(spl_autoload_functions())]);
        }
    }
}
