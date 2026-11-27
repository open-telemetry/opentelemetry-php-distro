--TEST--
Check if pre hook can return $params for internal function
--SKIPIF--
<?php if (PHP_VERSION_ID < 80200) die('skip requires PHP >= 8.2'); ?>
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'array_map', fn($obj, array $params) => $params);

array_map(var_dump(...), ['HELLO']);
?>
--EXPECT--
string(5) "HELLO"
