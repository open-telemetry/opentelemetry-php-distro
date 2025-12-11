<?php

declare(strict_types=1);

namespace OpenTelemetry\Distro;

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Registry;
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
    private static ?string $distroVersion = null;

    public static function register(string $nativePartVersion): void
    {
        self::$distroVersion = self::buildDistroVersion($nativePartVersion);
        Registry::registerResourceDetector(self::class, new self());
        BootstrapStageLogger::logDebug('Registered; distroVersion: ' . self::$distroVersion, __FILE__, __LINE__, __CLASS__, __FUNCTION__);
    }

    public function getResource(): ResourceInfo
    {
        $attributes = [
            ResourceAttributes::TELEMETRY_DISTRO_NAME => 'opentelemetry-php-distro',
            ResourceAttributes::TELEMETRY_DISTRO_VERSION => self::getDistroVersion(),
        ];

        BootstrapStageLogger::logDebug('Returning attributes: ' . json_encode($attributes), __FILE__, __LINE__, __CLASS__, __FUNCTION__);
        return ResourceInfo::create(Attributes::create($attributes), ResourceAttributes::SCHEMA_URL);
    }

    private static function buildDistroVersion(string $nativePartVersion): string
    {
        if ($nativePartVersion === PhpPartVersion::VALUE) {
            return $nativePartVersion;
        }

        $logMsg = 'Native part and PHP part versions do not match. native part version: ' . $nativePartVersion . '; PHP part version: ' . PhpPartVersion::VALUE;
        BootstrapStageLogger::logWarning($logMsg, __FILE__, __LINE__, __CLASS__, __FUNCTION__);
        return $nativePartVersion . '/' . PhpPartVersion::VALUE;
    }

    public static function getDistroVersion(): string
    {
        return self::$distroVersion ?? PhpPartVersion::VALUE;
    }
}
