--TEST--
Check if exceptions thrown in hooks work if custom error handler throws
--DESCRIPTION--
If the extension internally logs errors/warnings in a way that set_error_handler gets invoked, then any
exceptions/errors may cause the process to crash or hang if raising a throwable was not safe at that moment.
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
set_error_handler(function (int $errno, string $message) {
    throw new \Error('Throw from error handler: ' . $message);
});
function helloWorld() {
    throw new \Error('test');
}
\OpenTelemetry\Distro\hook(
    null,
    'helloWorld',
    pre: static function () : void {
        throw new \Exception('pre');
    }
);
helloWorld();
?>
--EXPECTF--
Fatal error: Uncaught Error: test in %s
Stack trace:
#0 %s: helloWorld()
#1 {main}
  thrown in %s