<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\OTelDistroScoperConfig;
use OpenTelemetry\Distro\Util\StaticClassTrait;
use OTelDistroTests\Util\Config\OptionForProdName;

use function OpenTelemetry\Distro\get_config_option_by_name;

final class AppCodeContextUtil
{
    use StaticClassTrait;

    public static function isScopingEnabled(): bool
    {
        return get_config_option_by_name(OptionForProdName::scoped_deps_enabled->name);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $unscopedClassName
     *
     * @return class-string<T>
     */
    public static function adaptClassNameToScoping(string $unscopedClassName): string
    {
        return self::adaptClassNameRawStringToScoping($unscopedClassName); // @phpstan-ignore return.type
    }

    public static function adaptClassNameRawStringToScoping(string $unscopedClassName): string
    {
        return (self::isScopingEnabled() ? (OTelDistroScoperConfig::PREFIX . '\\') : '') . $unscopedClassName;
    }
}
