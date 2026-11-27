--TEST--
Check if throwing an exception in post hook after IO operation will finish gracefully
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(
    null,
    'helloWorld',
    fn() => var_dump('pre'),
    fn(object|string|null $scope, array $params, mixed $returnValue, ?Throwable $throwable) => throw new Exception('error'));


function helloWorld() {
  // below scandir call or any other
  // IO operation is necessary to trigger
  // segfault.
  scandir(".");
}

try {
helloWorld();
} catch(Exception) {}

--EXPECT--
string(3) "pre"