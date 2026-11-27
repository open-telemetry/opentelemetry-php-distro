--TEST--
Check hooking interface method fires for implementing class
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(
    'MyInterface',
    'hello',
    function($obj, array $params, ?string $class, string $function) {
        var_dump('pre: ' . get_class($obj));
    },
    function($obj, array $params, mixed $ret, ?Throwable $e, ?string $class, string $function) {
        var_dump('post: ' . get_class($obj));
    }
);

interface MyInterface {
    public function hello(): void;
}

class MyClass implements MyInterface {
    public function hello(): void
    {
        var_dump('hello');
    }
}

$c = new MyClass();
$c->hello();
?>
--EXPECT--
string(12) "pre: MyClass"
string(5) "hello"
string(13) "post: MyClass"
