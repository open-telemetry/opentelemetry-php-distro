--TEST--
Check hook registered before class is defined still works
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook('LaterClass', 'hello', fn() => var_dump('PRE'), fn() => var_dump('POST'));

// Class defined after hook registration
class LaterClass {
    public static function hello(): void
    {
        var_dump('CALL');
    }
}

LaterClass::hello();
?>
--EXPECT--
string(3) "PRE"
string(4) "CALL"
string(4) "POST"
