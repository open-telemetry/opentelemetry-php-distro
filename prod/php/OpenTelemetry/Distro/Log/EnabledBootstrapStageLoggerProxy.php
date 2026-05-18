<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\Log;

final class EnabledBootstrapStageLoggerProxy
{
    public function __construct(
        private readonly int $logLevel,
    ) {
    }

    /**
     * @noinspection PhpUnused
     */
    public static function log(mixed $value): mixed
    {
    }
}
