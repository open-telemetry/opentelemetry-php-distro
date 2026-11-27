--TEST--
Check if exceptions thrown in hooks are isolated and logged
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'helloWorld', pre: fn() => throw new Exception('thrown in pre'), post: fn() => throw new Exception('thrown in post'));

function helloWorld() {
    var_dump('function');
    throw new Exception('original');
}

try {
    helloWorld();
} catch (Exception $e) {
    var_dump($e->getMessage());
    var_dump($e->getPrevious());
}
?>
--EXPECTF--
string(8) "function"
string(8) "original"
NULL