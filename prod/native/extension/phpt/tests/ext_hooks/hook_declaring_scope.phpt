--TEST--
Check declaring scope is provided for class method hooks
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(
    'ParentClass',
    'hello',
    function($obj, array $params, ?string $class, string $function) {
        var_dump('declaring_scope: ' . $class);
    }
);

class ParentClass {
    public function hello(): void {}
}

class ChildClass extends ParentClass {}

$c = new ChildClass();
$c->hello();
?>
--EXPECT--
string(28) "declaring_scope: ParentClass"
