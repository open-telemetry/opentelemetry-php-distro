<?php

declare(strict_types=1);

namespace OpenTelemetry\Distro\Util;

final class BoolUtil
{
    use StaticClassTrait;

    public static function toString(bool $val): string
    {
        return $val ? 'true' : 'false';
    }

    public static function parse(string $boolStringVal): ?bool
    {
        foreach (['true', 'yes', 'on', '1'] as $trueStringValue) {
            if (strcasecmp($boolStringVal, $trueStringValue) === 0) {
                return true;
            }
        }
        foreach (['false', 'no', 'off', '0'] as $falseStringValue) {
            if (strcasecmp($boolStringVal, $falseStringValue) === 0) {
                return false;
            }
        }

        return null;
    }
}
