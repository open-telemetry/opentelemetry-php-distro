--TEST--
Check class hooks are matched case-insensitively
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook('DEMO', 'HELLO', fn() => var_dump('PRE'), fn() => var_dump('POST'));

class Demo {
    public static function hello(): void
    {
        var_dump('CALL');
    }
}

Demo::hello();
?>
--EXPECT--
string(3) "PRE"
string(4) "CALL"
string(4) "POST"
