<?php
declare(strict_types=1);

$prefix = getenv('OTEL_PHP_SCOPER_PREFIX') ?: 'OTelDistroScoped';
$scopedClass = $prefix . '\\OpenTelemetry\\Distro\\PhpPartFacade';

if (!class_exists($scopedClass, false)) {
    eval("namespace {$prefix}\\OpenTelemetry\\Distro; " . <<<'PHP'
    final class PhpPartFacade {
        public static function bootstrap(string $nativePartVersion, int $maxEnabledLogLevel, float $requestInitStartTime): bool { return true; }
        public static function shutdown(): void {}
        public static function handleError(): void {}
        public static function inferredSpans(int $durationMs, bool $internalFunction): bool { return true; }
        public static function debugPreHook(mixed $object, array $params, ?string $class, string $function, ?string $filename, ?int $lineno): void {}
        public static function debugPostHook(mixed $object, array $params, mixed $retval, ?\Throwable $exception): void {}
    }
    PHP);
}
