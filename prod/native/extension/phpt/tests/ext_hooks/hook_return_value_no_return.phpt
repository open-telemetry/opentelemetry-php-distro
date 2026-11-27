--TEST--
Check post hook receives null return value for void function
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(
    null,
    'helloWorld',
    null,
    function($obj, array $params, mixed $ret) {
        var_dump($ret);
    }
);

function helloWorld(): void {
    // no return
}

helloWorld();
?>
--EXPECT--
NULL
