<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\Traces;

use OpenTelemetry\SDK\Common\Configuration\Resolver\CompositeResolver;
use OpenTelemetry\SDK\Trace\AutoRootSpan;

/**
 * The root span (transaction span) itself is created by the SDK's AutoRootSpan, including URL grouping,
 * request/response header capturing and request body capturing. The distro only turns it on by default.
 */
class RootSpan
{
    private static bool $defaultsRegistered = false;

    public static function startRootSpan(?callable $notifySpanEnded): void
    {
        // The SDK disables the root span by default, the distro enables it unless the user configured it.
        // See TransactionSpanDefaultsResolver for why this is not done with putenv().
        if (!self::$defaultsRegistered) {
            self::$defaultsRegistered = true;
            CompositeResolver::instance()->addResolver(new TransactionSpanDefaultsResolver());
        }

        AutoRootSpan::start($notifySpanEnded);
    }
}
