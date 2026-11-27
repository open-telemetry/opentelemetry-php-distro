--TEST--
Calling die/exit still executes post hooks
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--

<?php

use function OpenTelemetry\Distro\hook;

function goodbye() {
    var_dump('goodbye');
    die;
}

\OpenTelemetry\Distro\hook(null, 'goodbye', fn() => var_dump('PRE'), fn() => var_dump('POST'));

goodbye();
?>

--EXPECT--
string(3) "PRE"
string(7) "goodbye"
string(4) "POST"