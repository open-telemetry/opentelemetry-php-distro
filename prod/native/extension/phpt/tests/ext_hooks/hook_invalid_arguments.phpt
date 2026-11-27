--TEST--
Check hook() with invalid argument types
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
// Missing function_name (too few args)
try {
    \OpenTelemetry\Distro\hook(null);
} catch (ArgumentCountError $e) {
    var_dump('too few args caught');
}

// Non-closure for pre hook
try {
    \OpenTelemetry\Distro\hook(null, 'test', 'not_a_closure');
} catch (TypeError $e) {
    var_dump('type error caught');
}
?>
--EXPECT--
string(19) "too few args caught"
string(17) "type error caught"
