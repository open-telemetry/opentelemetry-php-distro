--TEST--
Pre hook adds an argument to a function called with unpacked arguments, call_user_func_array() and named arguments
--DESCRIPTION--
These calls grow the frame of the callee while the arguments are being sent, so the top of the VM stack is above the end of the frame the call was started with.
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
function f($a) {
    echo json_encode(func_get_args()), "\n";
}

\OpenTelemetry\Distro\hook(null, 'f', pre: fn() => [0 => 'x', 1 => 'extra'], post: fn() => null);

f('o');
$args = ['o'];
f(...$args);
call_user_func_array('f', $args);
f(a: 'o');
?>
--EXPECT--
["x","extra"]
["x","extra"]
["x","extra"]
["x","extra"]
