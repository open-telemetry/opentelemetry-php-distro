--TEST--
All post hooks run, in reverse order, when the hooked function calls exit()
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
function goodbye() {
    echo "goodbye\n";
    exit;
}

\OpenTelemetry\Distro\hook(null, 'goodbye', fn() => print("PRE 1\n"), fn() => print("POST 1\n"));
\OpenTelemetry\Distro\hook(null, 'goodbye', fn() => print("PRE 2\n"), fn() => print("POST 2\n"));

goodbye();
?>
--EXPECT--
PRE 1
PRE 2
goodbye
POST 2
POST 1
