<?php

declare(strict_types=1);

namespace OpenTelemetry\Distro;

use OpenTelemetry\Distro\Log\LogFeature;
use OpenTelemetry\Distro\Log\LoggingClassTrait;

/**
 * Bridges the app's own (unscoped) OpenTelemetry dependencies with the distro's scoped runtime -
 * this covers both instrumentation the app writes itself and officially published
 * open-telemetry-php auto-instrumentation packages the app installs on its own, outside the distro.
 *
 * When OTEL_PHP_SCOPED_DEPS_BRIDGE_ENABLED=true, {@see self::load()} requires the generated
 * unscoped_api_aliases.php which registers class_alias() entries mapping the scoped
 * OpenTelemetry\* classes back to their unscoped FQCNs. Registered before the app's Composer
 * autoloader runs, the app's own OpenTelemetry usage transparently shares the distro's
 * Globals / Context / TracerProvider instead of its own installed copy.
 *
 * At shutdown it warns if the app's installed open-telemetry/* versions differ from the versions
 * the distro bundles (which are the ones actually used at runtime). It only inspects
 * already-loaded state - it never forces loading.
 *
 * Code in this file is part of implementation internals, and thus it is not covered by
 * the backward compatibility.
 *
 * @internal
 */
final class ScopedDepsBridge
{
    use LoggingClassTrait;

    private const DEFAULT_DISTRO_NAME = 'OpenTelemetry PHP Distro';

    /**
     * Key packages to check. Each has a probe class used to detect whether the app loaded its own
     * copy before the bridge alias was applied.
     *
     * @var array<string, string>  package name => probe class
     */
    private const CHECK_PACKAGES = [
        'open-telemetry/api'     => 'OpenTelemetry\\API\\Globals',
        'open-telemetry/context' => 'OpenTelemetry\\Context\\Context',
        'open-telemetry/sdk'     => 'OpenTelemetry\\SDK\\Sdk',
    ];

    public static function load(?VendorCustomizationsInterface $vendorCustomizations): void
    {
        $envValue = getenv('OTEL_PHP_SCOPED_DEPS_BRIDGE_ENABLED');
        if ($envValue === false || strtolower($envValue) !== 'true') {
            return;
        }

        // The bridge only applies to scoped builds. Without scoping the distro already uses the
        // unscoped OpenTelemetry\* classes that the app's own OpenTelemetry usage uses, so no alias
        // is needed (the runtime is shared natively) and there is no scoped/unscoped version split
        // to check.
        // @noinspection PhpUnnecessaryFullyQualifiedNameInspection
        if (!\OpenTelemetry\Distro\get_config_option_by_name('scoped_deps_enabled')) {
            self::logDebug(__FUNCTION__)?->with(__LINE__, 'Scoping disabled; scoped deps bridge not needed');
            return;
        }

        $aliasFile = ProdPhpDir::$fullPath . 'unscoped_api_aliases.php';
        if (!file_exists($aliasFile)) {
            self::logError(__FUNCTION__)?->with(__LINE__, 'OTEL_PHP_SCOPED_DEPS_BRIDGE_ENABLED=true but alias file not found (non-scoped build?)', compact('aliasFile'));
            return;
        }

        $logDebug = self::logDebug(__FUNCTION__);
        $logDebug?->with(__LINE__, 'Loading scoped deps bridge', compact('aliasFile'));

        /** @var array<string,string> $_otelBundledVersions default overridden by the required file */
        $_otelBundledVersions = [];
        require $aliasFile;
        $bundledVersions = $_otelBundledVersions;
        unset($_otelBundledVersions);

        $logDebug?->with(__LINE__, 'Scoped deps bridge loaded');

        $distroName = $vendorCustomizations?->getDistributionName() ?? self::DEFAULT_DISTRO_NAME;
        register_shutdown_function(static function () use ($bundledVersions, $distroName): void {
            self::warnIfVersionMismatch($bundledVersions, $distroName);
        });
    }

    /**
     * @param array<string,string> $bundledVersions  package => version as bundled by this distro
     */
    private static function warnIfVersionMismatch(array $bundledVersions, string $distroName): void
    {
        if (empty($bundledVersions)) {
            return;
        }

        $scopedPrefix = OTelDistroScoperConfig::PREFIX . '\\';

        foreach (self::CHECK_PACKAGES as $package => $probeClass) {
            $distroVersion = $bundledVersions[$package] ?? null;
            if ($distroVersion === null) {
                continue;
            }

            // Check bridge alias validity: did the app's own copy of the probe class win over the alias?
            if (class_exists($probeClass, false) || interface_exists($probeClass, false)) {
                $resolvedName = (new \ReflectionClass($probeClass))->getName();
                if (!str_starts_with($resolvedName, $scopedPrefix)) {
                    self::logWarning(__FUNCTION__)?->with(
                        __LINE__,
                        'OTEL_PHP_SCOPED_DEPS_BRIDGE_ENABLED: the app loaded its own '
                        . $probeClass . ' before the bridge alias was applied - '
                        . 'the app\'s OpenTelemetry usage will not share the ' . $distroName . ' runtime (separate Globals/Context). '
                        . 'Ensure the ' . $distroName . ' bootstrap runs before the app\'s Composer autoloader.',
                        ['package' => $package, 'distro_bundled_version' => $distroVersion, 'app_version' => self::appInstalledVersion($package) ?? 'unknown']
                    );
                    continue;
                }
            }

            // Version mismatch check. getPrettyVersion() returns the same "pretty" form as the
            // bundled versions (e.g. "1.14.0"), so no normalisation is needed.
            $appVersion = self::appInstalledVersion($package);
            if ($appVersion === null) {
                continue; // user does not have this package installed
            }

            if ($appVersion !== $distroVersion) {
                self::logWarning(__FUNCTION__)?->with(
                    __LINE__,
                    'OTEL_PHP_SCOPED_DEPS_BRIDGE_ENABLED: version mismatch for ' . $package . '. '
                    . $distroName . ' bundles a different version than the app has installed. '
                    . 'This may cause incompatibilities.',
                    ['package' => $package, 'distro_bundled_version' => $distroVersion, 'app_version' => $appVersion]
                );
            }
        }
    }

    /**
     * Return the app's installed pretty version of a package, or null if it is not installed.
     *
     * Uses the app's unscoped Composer\InstalledVersions. The class name is assembled at runtime
     * so php-scoper does not rewrite it to OTelDistroScoped\Composer\InstalledVersions (which would
     * report the distro's own bundled versions instead of the app's). Only referenced at shutdown,
     * so autoloading it here has no effect on the OTel runtime.
     */
    private static function appInstalledVersion(string $package): ?string
    {
        /** @var class-string $installedVersions */
        $installedVersions = 'Composer\\' . 'InstalledVersions';
        if (!class_exists($installedVersions)) {
            return null;
        }
        try {
            /** @var string $version */
            $version = $installedVersions::getPrettyVersion($package);
            return $version;
        } catch (\OutOfBoundsException) {
            return null; // package not installed in the app
        }
    }

    /**
     * Must be defined in class using LoggingClassTrait
     */
    private static function getCurrentSourceCodeFile(): string
    {
        return __FILE__;
    }

    /**
     * Must be defined in class using LoggingClassTrait
     */
    private static function getCurrentOptionalLogProdFeatureIntOrCategoryString(): int
    {
        return LogFeature::BOOTSTRAP;
    }
}
