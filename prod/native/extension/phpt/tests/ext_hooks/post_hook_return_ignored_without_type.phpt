--TEST--
Check if post hook return value is ignored if return typehint not supplied
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'helloWorld', post: fn(mixed $object, array $params, string $return) => 'ignored');

function helloWorld(string $val) {
    return $val;
}

var_dump(helloWorld('foo'));
?>
--EXPECT--
string(3) "foo"
