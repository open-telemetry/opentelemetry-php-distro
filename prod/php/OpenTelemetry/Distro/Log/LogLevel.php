<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\Log;

use OpenTelemetry\Distro\Util\EnumUtilTrait;

enum LogLevel: int
{
    use EnumUtilTrait;

    case off      = 0;
    case critical = 1;
    case error    = 2;
    case warning  = 3;
    case info     = 4;
    case debug    = 5;
    case trace    = 6;

    public static function fromPsrLevel(string $level): ?self
    {
        return match ($level) {
            \Psr\Log\LogLevel::EMERGENCY, \Psr\Log\LogLevel::ALERT, \Psr\Log\LogLevel::CRITICAL => self::critical,
            \Psr\Log\LogLevel::ERROR => self::error,
            \Psr\Log\LogLevel::WARNING => self::warning,
            \Psr\Log\LogLevel::NOTICE, \Psr\Log\LogLevel::INFO => self::info,
            \Psr\Log\LogLevel::DEBUG => self::debug,
            default => null,
        };
    }
}
