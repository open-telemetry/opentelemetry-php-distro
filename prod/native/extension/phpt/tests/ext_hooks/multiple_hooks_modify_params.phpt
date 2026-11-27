--TEST--
Check if hooks receive modified arguments
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'helloWorld', fn(mixed $object, array $params) => [++$params[0]]);
\OpenTelemetry\Distro\hook(null, 'helloWorld', fn(mixed $object, array $params) => [++$params[0]]);

function helloWorld($a) {
    var_dump($a);
}

helloWorld(1);
?>
--EXPECT--
int(3)
