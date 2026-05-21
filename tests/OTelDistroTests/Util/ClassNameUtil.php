<?php

declare(strict_types=1);

namespace OTelDistroTests\Util;

use OpenTelemetry\Distro\Util\StaticClassTrait;

final class ClassNameUtil
{
    use StaticClassTrait;

    /**
     * @param class-string $fqClassName
     */
    public static function fqToShort(string $fqClassName): string
    {
        $namespace = '';
        $shortName = '';
        self::splitFqClassName($fqClassName, /* ref */ $namespace, /* ref */ $shortName);
        return $shortName;
    }

    /**
     * @param class-string $fqClassName
     */
    public static function fqToNamespace(string $fqClassName): string
    {
        $namespace = '';
        $shortName = '';
        self::splitFqClassName($fqClassName, /* ref */ $namespace, /* ref */ $shortName);
        return $namespace;
    }

    public static function fqToShortFromRawString(string $fqClassName): string
    {
        return self::fqToShort($fqClassName); // @phpstan-ignore argument.type
    }

    /**
     * @param class-string $fqClassName
     */
    public static function splitFqClassName(string $fqClassName, /* out */ string &$namespace, /* out */ string &$shortName): void
    {
        // Check if $fqClassName begin with a back slash(es)
        $firstBackSlashPos = strpos($fqClassName, '\\');
        if ($firstBackSlashPos === false) {
            $namespace = '';
            $shortName = $fqClassName;
            return;
        }
        $firstCanonPos = $firstBackSlashPos === 0 ? 1 : 0;

        $lastBackSlashPos = strrpos($fqClassName, '\\', $firstCanonPos);
        if ($lastBackSlashPos === false) {
            $namespace = '';
            $shortName = substr($fqClassName, $firstCanonPos);
            return;
        }

        $namespace = substr($fqClassName, $firstCanonPos, $lastBackSlashPos - $firstCanonPos);
        $shortName = substr($fqClassName, $lastBackSlashPos + 1);
    }
}
