--TEST--
Check hooks on multiple methods of the same class
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook('Demo', 'methodA', fn() => var_dump('pre-A'));
\OpenTelemetry\Distro\hook('Demo', 'methodB', fn() => var_dump('pre-B'));

class Demo {
    public function methodA(): void { var_dump('A'); }
    public function methodB(): void { var_dump('B'); }
}

$d = new Demo();
$d->methodA();
$d->methodB();
?>
--EXPECT--
string(5) "pre-A"
string(1) "A"
string(5) "pre-B"
string(1) "B"
