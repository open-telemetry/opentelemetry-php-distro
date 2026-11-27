--TEST--
Check if hooks receive modified return value
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'helloWorld', post: fn(mixed $object, array $params, int $return): int => ++$return);
\OpenTelemetry\Distro\hook(null, 'helloWorld', post: fn(mixed $object, array $params, int $return): int => ++$return);

function helloWorld(int $val): int {
    return $val;
}

var_dump(helloWorld(1));
?>
--EXPECT--
int(3)
