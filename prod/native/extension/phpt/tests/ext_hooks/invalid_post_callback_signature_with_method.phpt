--TEST--
Test invalid post callback signature for class method
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
OpenTelemetry\Distro\hook(
    'TestClass',
    'test',
    null,
    static function (array $params) {
        //missing param 1 (object), incorrect type for param 1
        var_dump('post');
    }
);

class TestClass {
    public static function test(): void
    {
        var_dump('test');
    }
}

TestClass::test();
?>
--EXPECTF--
string(4) "test"

