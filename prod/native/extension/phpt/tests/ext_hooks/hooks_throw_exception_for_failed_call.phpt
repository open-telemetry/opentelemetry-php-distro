--TEST--
Check if exceptions thrown in hooks interfere with internal exceptions
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
function helloWorld($argument) {
    var_dump('inside');
}
\OpenTelemetry\Distro\hook(
    null,
    'helloWorld',
    pre: static function () : void {
        throw new \Exception('pre');
    },
    post: static function () : void {
        throw new \Exception('post');
    }
);
helloWorld();
?>
--EXPECTF--

Fatal error: Uncaught ArgumentCountError: Too few arguments to function helloWorld(), 0 passed in %s
Stack trace:
#0 %s: helloWorld()
#1 {main}
  thrown in %s
