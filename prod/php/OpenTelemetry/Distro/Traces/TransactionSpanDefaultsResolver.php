<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\Traces;

use OpenTelemetry\SDK\Common\Configuration\Resolver\ResolverInterface;

/**
 * Supplies the distro's defaults for the root span (transaction span) options to the OpenTelemetry SDK configuration.
 *
 * It is added at the end of the SDK's resolvers, so an environment variable or a php.ini setting always wins over it.
 *
 * It is used instead of putenv() on purpose: the process environment is shared with the application's own copy of the
 * SDK. Since SDK 1.16 that copy would see the variable, start its own root span while Composer is still loading files
 * and build its tracer provider before the OTLP exporter is registered, which leaves the application's own SDK
 * without an exporter. A resolver belongs to one copy of the SDK classes, so only the distro's runtime gets the defaults.
 *
 * @internal
 */
final class TransactionSpanDefaultsResolver implements ResolverInterface
{
    private const DEFAULTS = [
        'OTEL_PHP_TRANSACTION_SPAN_ENABLED' => 'true',
        'OTEL_PHP_TRANSACTION_SPAN_ENABLED_CLI' => 'true',
    ];

    #[\Override]
    public function retrieveValue(string $variableName): mixed
    {
        return self::DEFAULTS[$variableName] ?? null;
    }

    #[\Override]
    public function hasVariable(string $variableName): bool
    {
        return array_key_exists($variableName, self::DEFAULTS);
    }
}
