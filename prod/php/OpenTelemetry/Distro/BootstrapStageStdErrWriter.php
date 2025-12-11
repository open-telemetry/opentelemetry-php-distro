<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro;

final class BootstrapStageStdErrWriter
{
    private static ?bool $isStderrDefined = null;

    private static function ensureStdErrIsDefined(): bool
    {
        if (self::$isStderrDefined === null) {
            if (defined('STDERR')) {
                self::$isStderrDefined = true;
            } else {
                define('STDERR', fopen('php://stderr', 'w'));
                self::$isStderrDefined = defined('STDERR');
            }
        }

        return self::$isStderrDefined;
    }

    public static function writeLine(string $text): void
    {
        if (self::ensureStdErrIsDefined()) {
            fwrite(STDERR, $text . PHP_EOL);
        }
    }
}
