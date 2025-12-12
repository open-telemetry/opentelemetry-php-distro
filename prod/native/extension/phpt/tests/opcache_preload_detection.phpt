--TEST--
Detection of opcache preload feature
--ENV--
OTEL_PHP_LOG_LEVEL_STDERR=DEBUG
OTEL_PHP_ENABLED=true
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/includes/bootstrap_mock.inc
opentelemetry_distro.enabled = 1
opcache.enable=1
opcache.enable_cli=1
opcache.optimization_level=-1
opcache.preload={PWD}/opcache_preload_detection.inc
opcache.preload_user=root
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70400) die("skip OpenTelemetryTest Unsupported PHP version");
?>
--FILE--
<?php
declare(strict_types=1);

echo 'Preloaded function exists: '.function_exists('preloadedFunction').PHP_EOL;
echo 'Test completed';
?>
--EXPECTF--
%aopcache.preload request detected on init%aopcache.preload request detected on shutdown%aPreloaded function exists: 1
Test completed%a