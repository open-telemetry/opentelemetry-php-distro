--TEST--
Check filename and lineno are null for internal functions
--SKIPIF--
<?php if (PHP_VERSION_ID < 80200) die('skip requires PHP >= 8.2'); ?>
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(
    null,
    'array_map',
    function($obj, array $params, ?string $class, string $function, ?string $filename, ?int $lineno) {
        var_dump($filename);
        var_dump($lineno);
    }
);

array_map('strtoupper', ['hello']);
?>
--EXPECT--
NULL
NULL
