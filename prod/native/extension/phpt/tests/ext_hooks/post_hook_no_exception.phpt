--TEST--
Check post hook receives null exception when function succeeds
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(
    null,
    'helloWorld',
    null,
    function($obj, array $params, mixed $ret, ?Throwable $e) {
        var_dump($ret);
        var_dump($e);
    }
);

function helloWorld(): string {
    return 'success';
}

helloWorld();
?>
--EXPECT--
string(7) "success"
NULL
