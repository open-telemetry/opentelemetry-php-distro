<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\Util;

final class NumericUtil
{
    use StaticClassTrait;

    /**
     * @param float|int $intervalLeft
     * @param float|int $x
     * @param float|int $intervalRight
     *
     * @return bool
     */
    public static function isInClosedInterval(float|int $intervalLeft, float|int $x, float|int $intervalRight): bool
    {
        return ($intervalLeft <= $x) && ($x <= $intervalRight);
    }
}
