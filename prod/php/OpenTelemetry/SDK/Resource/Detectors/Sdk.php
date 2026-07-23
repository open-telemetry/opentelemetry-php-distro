<?php

declare(strict_types=1);

namespace OpenTelemetry\SDK\Resource\Detectors;

use Composer\InstalledVersions;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceDetectorInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SemConv\Attributes\TelemetryAttributes;
use OpenTelemetry\SemConv\Version;

use function class_exists;

/**
 * Shadow of SDK's built-in Sdk resource detector.
 * Identical to the SDK version except telemetry.distro.name / telemetry.distro.version are
 * omitted: those attributes are set by OverrideOTelSdkResourceAttributes (registered in the
 * SDK Registry and therefore merged into the resource before this detector runs).
 * Without this shadow, SDK >=1.15.0's Sdk detector would overwrite our distro name with
 * "opentelemetry-php-instrumentation" because it runs last in the Composite chain.
 */
final class Sdk implements ResourceDetectorInterface
{
    private const PACKAGES = [
        'open-telemetry/sdk',
        'open-telemetry/opentelemetry',
    ];

    #[\Override]
    public function getResource(): ResourceInfo
    {
        $attributes = [
            TelemetryAttributes::TELEMETRY_SDK_NAME => 'opentelemetry',
            TelemetryAttributes::TELEMETRY_SDK_LANGUAGE => 'php',
        ];

        if (class_exists(InstalledVersions::class)) {
            foreach (self::PACKAGES as $package) {
                if (!InstalledVersions::isInstalled($package)) {
                    continue;
                }
                if (($version = InstalledVersions::getPrettyVersion($package)) === null) {
                    continue;
                }

                $attributes[TelemetryAttributes::TELEMETRY_SDK_VERSION] = $version;

                break;
            }
        }

        return ResourceInfo::create(Attributes::create($attributes), Version::VERSION_1_38_0->url());
    }
}
