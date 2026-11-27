--TEST--
Check if post hook can modify return value
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'helloWorld', null, fn(): int => 17);

function helloWorld() {
    return 42;
}

var_dump(helloWorld());
?>
--EXPECT--
int(17)
