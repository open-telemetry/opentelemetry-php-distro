--TEST--
Check hook registered before function is defined still works
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'laterFunction', fn() => var_dump('PRE'), fn() => var_dump('POST'));

// Function defined after hook registration
function laterFunction() {
    var_dump('CALL');
}

laterFunction();
?>
--EXPECT--
string(3) "PRE"
string(4) "CALL"
string(4) "POST"
