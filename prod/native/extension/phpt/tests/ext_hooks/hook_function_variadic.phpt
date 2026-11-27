--TEST--
Check hook on function with variadic parameters
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(
    null,
    'helloWorld',
    function($obj, array $params) {
        var_dump(count($params));
    },
    function($obj, array $params, mixed $ret) {
        var_dump(count($params));
    }
);

function helloWorld(string ...$args) {
    return implode(',', $args);
}

helloWorld('a', 'b', 'c', 'd');
?>
--EXPECT--
int(4)
int(4)
