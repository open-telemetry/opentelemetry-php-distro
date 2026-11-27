--TEST--
Check post hook receives both null return and exception when function throws
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
        var_dump($e instanceof Exception);
        var_dump($e->getMessage());
    }
);

function helloWorld() {
    throw new Exception('boom');
}

try {
    helloWorld();
} catch (Exception $e) {
    var_dump('caught: ' . $e->getMessage());
}
?>
--EXPECT--
NULL
bool(true)
string(4) "boom"
string(12) "caught: boom"
