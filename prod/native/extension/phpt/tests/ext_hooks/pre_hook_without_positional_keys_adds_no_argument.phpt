--TEST--
Pre hook that returns only named keys does not add an argument
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'f', pre: fn() => ['unknown' => 1], post: fn() => null);

function f($a) {
    echo "body\n";
}

try {
    f();
} catch (ArgumentCountError $e) {
    echo "ArgumentCountError\n";
}

function g() {
    var_dump(func_num_args());
}
\OpenTelemetry\Distro\hook(null, 'g', pre: fn() => ['unknown' => 1], post: fn() => null);
g();
?>
--EXPECT--
ArgumentCountError
int(0)
