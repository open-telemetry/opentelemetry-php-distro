<?php

/** @noinspection PhpUnusedParameterInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro;

use Closure;
use Throwable;

/**
 * This function is implemented by the extension
 *
 * @return ?array<array-key, mixed>
 */
function get_remote_configuration(): ?array // @phpstan-ignore return.unusedType
{
    return ['dummy file name' => 'dummy file content (JSON)'];
}

/**
 * This function is implemented by the extension
 *
 * @noinspection PhpUnusedParameterInspection
 */
function log_feature(
    int $isForced,
    int $level,
    int $feature,
    string $file,
    ?int $line,
    string $func,
    string $message
): void {
}

/**
 * This function is implemented by the extension
 *
 * @noinspection PhpUnusedParameterInspection
 */
function get_config_option_by_name(string $optionName): mixed
{
    return null;
}

/**
 * This function is implemented by the extension
 *
 * @phpstan-param ?string $class The hooked function's class. Null for a global/built-in function.
 * @phpstan-param string $function The hooked function's name.
 * @phpstan-param null|(Closure(?object $thisObj, list<mixed> $params, string $class, string $function, ?string $filename, ?int $lineno): (void|list<mixed>)) $pre
 *                              if not void then the return value is modified parameters
 * @phpstan-param null|(Closure(?object $thisObj, list<mixed> $params, mixed $returnValue, ?Throwable $throwable): (void|mixed)) $post
 *                              if not void then the return value is modified return value
 *
 * @return bool Whether the observer was successfully added
 *
 * @see https://github.com/open-telemetry/opentelemetry-php-instrumentation
 *
 * @noinspection PhpUnusedParameterInspection
 */
function hook(?string $class, string $function, ?Closure $pre, ?Closure $post): bool
{
    return false;
}

/**
 * This function is implemented by the extension
 */
function is_enabled(): bool
{
    return false;
}
