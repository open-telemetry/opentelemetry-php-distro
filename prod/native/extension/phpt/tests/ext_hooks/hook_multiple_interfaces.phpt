--TEST--
Check hooks work with class implementing multiple interfaces
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(
    'InterfaceA',
    'hello',
    function($obj) {
        var_dump('pre-A');
    },
    null
);

\OpenTelemetry\Distro\hook(
    'InterfaceB',
    'greet',
    function($obj) {
        var_dump('pre-B');
    },
    null
);

interface InterfaceA {
    public function hello(): void;
}

interface InterfaceB {
    public function greet(): void;
}

class MyClass implements InterfaceA, InterfaceB {
    public function hello(): void
    {
        var_dump('hello');
    }
    public function greet(): void
    {
        var_dump('greet');
    }
}

$c = new MyClass();
$c->hello();
$c->greet();
?>
--EXPECT--
string(5) "pre-A"
string(5) "hello"
string(5) "pre-B"
string(5) "greet"
