<?php

declare(strict_types=1);

namespace OpenTelemetry\Distro;

/**
 * Interface for vendor-specific customizations of the OpenTelemetry PHP Distro.
 *
 * Vendors (e.g., Elastic, Datadog) implement this interface to inject their own
 * behavior without forking the upstream distribution. The implementation is registered
 * via {@see PhpPartFacade::setVendorCustomizations()} before the bootstrap sequence.
 *
 * @api
 */
interface VendorCustomizationsInterface
{
    /**
     * Returns the vendor identifier (e.g., 'elastic', 'datadog').
     */
    public function getVendorName(): string;

    /**
     * Returns the distribution name for telemetry.distro.name resource attribute.
     */
    public function getDistributionName(): string;

    /**
     * Returns the distribution version for telemetry.distro.version resource attribute.
     */
    public function getDistributionVersion(): string;

    /**
     * Returns the User-Agent string to use in OTLP HTTP transport requests.
     * If null, the default upstream User-Agent is used.
     */
    public function getUserAgentString(): ?string;

    /**
     * Returns additional resource attributes to set on the OTel SDK resource.
     *
     * @return array<string, string> Attribute name => value pairs
     */
    public function getResourceAttributes(): array;
}
