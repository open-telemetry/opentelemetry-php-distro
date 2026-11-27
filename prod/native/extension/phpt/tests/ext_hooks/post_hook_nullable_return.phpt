--TEST--
Check post hook can return null to replace return value when return type is nullable
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'helloWorld', post: function(mixed $object, array $params, ?string $return): ?string {
    return null;
});

function helloWorld(string $val): ?string {
    return $val;
}

var_dump(helloWorld('foo'));
?>
--EXPECT--
NULL
