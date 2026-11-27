--TEST--
Check hooking static class methods provides class name as 1st param
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(
    Demo::class,
    'hello',
    function($class) {
        var_dump($class);
    },
    function($class) {
        var_dump($class);
    }
);

class Demo {
    public static function hello(): void
    {
        var_dump('hello');
    }
}

Demo::hello();
?>
--EXPECT--
string(4) "Demo"
string(5) "hello"
string(4) "Demo"
