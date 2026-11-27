--TEST--
Check if post hook receives exception
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(
    null,
    'helloWorld',
    null,
    fn(object|null $obj, array $params, mixed $returnValue, ?Throwable $throwable) => var_dump($throwable?->getMessage()));

function helloWorld() {
    throw new Exception('error');
}

try {
    helloWorld();
} catch (Exception $e) {}
?>
--EXPECT--
string(5) "error"
