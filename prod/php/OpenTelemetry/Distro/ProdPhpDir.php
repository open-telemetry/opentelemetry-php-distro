<?php

declare(strict_types=1);

namespace OpenTelemetry\Distro;

final class ProdPhpDir
{
    /** @var string */
    public static $fullPath;

    /** @var ?string */
    public static $shadowOtelRootPath;

    public static function getOpenTelemetryRootPath(): string
    {
        if (is_string(self::$shadowOtelRootPath) && self::$shadowOtelRootPath !== '') {
            return self::$shadowOtelRootPath;
        }

        return self::$fullPath . DIRECTORY_SEPARATOR . 'OpenTelemetry';
    }
}
