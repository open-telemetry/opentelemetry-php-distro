--TEST--
Pre hook skips a parameter whose default value cannot be evaluated
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'g', pre: fn() => [0 => 'a', 2 => 'c'], post: fn() => null);

function g($a, $b = Missing\Cls::X, $c = null) {
    var_dump($a, $b, $c);
}

g();
echo "done\n";
?>
--EXPECT--
string(1) "a"
NULL
string(1) "c"
done