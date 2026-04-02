<?php

declare(strict_types=1);

namespace OpenTelemetry\Distro;

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Registry as OTelSdkRegistry;
use OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class OverrideOTelSdkResourceAttributes implements ResourceDetectorInterface
{
    use BootstrapStageLoggingClassTrait;

    private static ?string $distroVersion = null;

    public static function register(string $nativePartVersion): void
    {
        self::$distroVersion = self::buildDistroVersion($nativePartVersion);
        OTelSdkRegistry::registerResourceDetector(self::class, new self());
        self::logDebug(__LINE__, __FUNCTION__, 'Exiting', ['distroVersion' => self::$distroVersion]);
    }

    public function getResource(): ResourceInfo
    {
        $attributes = [
            ResourceAttributes::TELEMETRY_DISTRO_NAME => 'opentelemetry-php-distro',
            ResourceAttributes::TELEMETRY_DISTRO_VERSION => self::getDistroVersion(),
        ];

        self::logDebug(__LINE__, __FUNCTION__, 'Exiting', compact('attributes'));
        return ResourceInfo::create(Attributes::create($attributes), ResourceAttributes::SCHEMA_URL);
    }

    private static function buildDistroVersion(string $nativePartVersion): string
    {
        if ($nativePartVersion === PhpPartVersion::VALUE) {
            return $nativePartVersion;
        }

        self::logWarning(__LINE__, __FUNCTION__, 'Native part and PHP part versions do NOT match', ['native part version' => $nativePartVersion, 'PHP part version' => PhpPartVersion::VALUE]);
        return $nativePartVersion . '/' . PhpPartVersion::VALUE;
    }

    public static function getDistroVersion(): string
    {
        return self::$distroVersion ?? PhpPartVersion::VALUE;
    }

    /**
     * Must be defined in class using BootstrapStageLoggingClassTrait
     */
    private static function getCurrentSourceCodeFile(): string
    {
        return __FILE__;
    }

    /**
     * Must be defined in class using BootstrapStageLoggingClassTrait
     */
    private static function getCurrentSourceCodeClass(): string
    {
        return __CLASS__;
    }
}
