<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\OTelDistroScoperConfig;
use OpenTelemetry\Distro\Util\StaticClassTrait;
use OTelDistroTests\Util\Config\OptionForProdName;

final class AppCodeContextUtil
{
    use StaticClassTrait;

    /**
     * @template T of object
     *
     * @param class-string<T> $unscopedClassName
     *
     * @return class-string<T>
     */
    public static function adaptClassName(string $unscopedClassName): string
    {
        /** @noinspection PhpFullyQualifiedNameUsageInspection */
        $isScoperEnabled = \OpenTelemetry\Distro\get_config_option_by_name(OptionForProdName::debug_scoper_enabled->name);
        return ($isScoperEnabled ? (OTelDistroScoperConfig::PREFIX . '\\') : '') . $unscopedClassName; // @phpstan-ignore return.type
    }
}
