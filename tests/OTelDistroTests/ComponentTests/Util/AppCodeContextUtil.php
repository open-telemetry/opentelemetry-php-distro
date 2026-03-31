<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\OTelDistroScoperConfig;
use OpenTelemetry\Distro\Util\StaticClassTrait;

final class AppCodeContextUtil
{
    use StaticClassTrait;

    /**
     * @template T of object
     *
     * @param class-string<T> $unscopedClassName
     *
     * @phpstan-return class-string<T>
     */
    public static function adaptClassName(string $unscopedClassName): string
    {
        return (OTelDistroScoperConfig::ENABLED ? (OTelDistroScoperConfig::PREFIX . '\\') : '') . $unscopedClassName; // @phpstan-ignore return.type, ternary.alwaysTrue
    }
}
