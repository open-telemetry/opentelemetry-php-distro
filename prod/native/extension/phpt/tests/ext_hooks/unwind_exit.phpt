--TEST--
Test UnwindExit from die/exit is not exposed to userland code
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--

<?php

use function OpenTelemetry\Distro\hook;

class TestClass {
    public static function run(): void
    {
       die('exit!');
    }
}

hook(
    'TestClass',
    'run',
    null,
    static function ($object, array $params, mixed $ret, ?\Throwable $exception ) {
      echo PHP_EOL . 'post';
    }
);

TestClass::run();
?>

--EXPECT--
exit!
post
