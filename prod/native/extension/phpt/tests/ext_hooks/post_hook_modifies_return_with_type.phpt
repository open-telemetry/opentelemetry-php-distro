--TEST--
Check post hook replaces return value when it has a non-void return type
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'helloWorld', post: function(mixed $object, array $params, string $return): string {
    return 'replaced';
});

function helloWorld(string $val): string {
    return $val;
}

var_dump(helloWorld('original'));
?>
--EXPECT--
string(8) "replaced"
