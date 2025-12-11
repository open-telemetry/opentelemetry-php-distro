<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\Util;

trait EnumUtilTrait
{
    public static function tryToFindByName(string $enumName): ?self
    {
        /** @var ?array<string, self> $mapByName */
        static $mapByName = null;

        if ($mapByName === null) {
            $mapByName = [];
            foreach (self::cases() as $enumCase) {
                $mapByName[$enumCase->name] = $enumCase;
            }
        }

        if (!array_key_exists($enumName, $mapByName)) {
            return null;
        }
        return $mapByName[$enumName];
    }
}
