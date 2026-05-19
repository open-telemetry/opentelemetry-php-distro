<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Log;

use OpenTelemetry\Distro\Util\StaticClassTrait;
use OTelDistroTests\Util\Log\LogBackendForTests;
use OpenTelemetry\Distro\Log\LogLevel;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class NoopLoggerFactory
{
    use StaticClassTrait;

    private static ?LoggerFactory $singletonInstance = null;

    public static function singletonInstance(): LoggerFactory
    {
        if (self::$singletonInstance === null) {
            self::$singletonInstance = new LoggerFactory(new LogBackendForTests(LogLevel::off, NoopLogSink::singletonInstance()));
        }
        return self::$singletonInstance;
    }
}
