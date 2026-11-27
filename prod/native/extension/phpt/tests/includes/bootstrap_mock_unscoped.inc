<?php
declare(strict_types=1);

// Same as bootstrap_mock.inc but for runs with OTEL_PHP_SCOPED_DEPS_ENABLED=false,
// where the extension calls the unscoped OpenTelemetry\Distro\PhpPartFacade.
namespace OpenTelemetry\Distro;

if (!class_exists(PhpPartFacade::class, false)) {
    final class PhpPartFacade {
        public static function bootstrap(string $nativePartVersion, int $maxEnabledLogLevel, float $requestInitStartTime): bool { return true; }
        public static function shutdown(): void {}
        public static function handleError(): void {}
        public static function inferredSpans(int $durationMs, bool $internalFunction): bool { return true; }
        public static function inferredSpansHookedFunction(bool $exiting): bool { return true; }
        public static function debugPreHook(mixed $object, array $params, ?string $class, string $function, ?string $filename, ?int $lineno): void {}
        public static function debugPostHook(mixed $object, array $params, mixed $retval, ?\Throwable $exception): void {}
    }
}
