--TEST--
Check hooks on multiple different functions work independently
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'funcA', fn() => var_dump('pre-A'));
\OpenTelemetry\Distro\hook(null, 'funcB', fn() => var_dump('pre-B'));

function funcA() { var_dump('A'); }
function funcB() { var_dump('B'); }

funcA();
funcB();
funcA();
?>
--EXPECT--
string(5) "pre-A"
string(1) "A"
string(5) "pre-B"
string(1) "B"
string(5) "pre-A"
string(1) "A"
