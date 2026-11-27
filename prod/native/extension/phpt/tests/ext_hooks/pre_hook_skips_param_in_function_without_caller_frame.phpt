--TEST--
Pre hook skips a parameter of a function that has no caller frame (a shutdown function)
--DESCRIPTION--
The default of a skipped parameter is evaluated on a fake frame whose predecessor is the frame of the caller. A shutdown function is called from C, without one.
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
memory_limit=32M
--FILE--
<?php
function s($a, $b = UNDEFINED_CONSTANT_FOR_TEST, $c = null) {
    var_dump($a, $c);
}

\OpenTelemetry\Distro\hook(null, 's', pre: fn() => [0 => 'a', 2 => 'c'], post: fn() => null);

register_shutdown_function('s');
echo "main done\n";
?>
--EXPECT--
main done
string(1) "a"
string(1) "c"
