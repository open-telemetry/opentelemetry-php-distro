--TEST--
Check hooking instance method provides object as 1st param
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(
    Demo::class,
    'hello',
    function($obj) {
        var_dump(get_class($obj));
    },
    function($obj) {
        var_dump(get_class($obj));
    }
);

class Demo {
    public function hello(): void
    {
        var_dump('hello');
    }
}

$d = new Demo();
$d->hello();
?>
--EXPECT--
string(4) "Demo"
string(5) "hello"
string(4) "Demo"
