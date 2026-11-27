--TEST--
Check if calling die or exit will finish gracefully
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--

<?php

use function OpenTelemetry\Distro\hook;

class TestClass {
    public static function countFunction(): void
    {
       for ($i = 1; $i <= 300; $i++) {
            if ($i === 200) {
                die('exit!');
            }
       }
    }
}

hook(
    'TestClass',
    'countFunction',
    null,
    post: static function ($object, array $params, mixed $ret, ?\Throwable $exception ) {}
);

try{
TestClass::countFunction();
}
catch(Exception) {}
// Comment out line below and revert fix in order to trigger segfault
// reproduction frequency depends on platform
catch(TypeError) {}
?>

--EXPECT--
exit!
