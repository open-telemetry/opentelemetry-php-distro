--TEST--
Check if hooks are invoked only once for reimplemented interfaces
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
interface A {
    function m(): void;
}
interface B extends A {
}
class C implements A, B {
    function m(): void {}
}

\OpenTelemetry\Distro\hook(A::class, 'm', fn() => var_dump('PRE'), fn() => var_dump('POST'));

(new C)->m();
?>
--EXPECT--
string(3) "PRE"
string(4) "POST"
