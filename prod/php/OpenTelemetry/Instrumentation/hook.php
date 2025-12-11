<?php

/**
 * @noinspection PhpInternalEntityUsedInspection
 */

declare(strict_types=1);

namespace OpenTelemetry\Instrumentation;

use Closure;
use OpenTelemetry\Distro\InstrumentationBridge;
use Throwable;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * Called by OTel instrumentations
 *
 * @internal
 *
 * @phpstan-param ?string $class The hooked function's class. Null for a global/built-in function.
 * @phpstan-param string $function The hooked function's name.
 * @phpstan-param ?(Closure(?object $thisObj, array<mixed> $params, string $class, string $function, ?string $filename, ?int $lineno): (void|array<mixed>)) $pre
 *                                 return value is modified parameters
 * @phpstan-param ?(Closure(?object $thisObj, array<mixed> $params, mixed $returnValue, ?Throwable $throwable): mixed) $post
 *                                 return value is modified return value
 *
 * @return bool Whether the observer was successfully added
 *
 * @see https://github.com/open-telemetry/opentelemetry-php-instrumentation
 */
function hook(
    ?string $class,
    string $function,
    ?Closure $pre = null,
    ?Closure $post = null,
): bool {
    return InstrumentationBridge::singletonInstance()->hook($class, $function, $pre, $post);
}
