--TEST--
Pre hook adds arguments with gaps to an internal function, the gaps have no default
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'str_contains', pre: fn() => [0 => 'abc', 1 => 'b', 4 => 'x'], post: fn() => null);

try {
    var_dump(str_contains('abc', 'b'));
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
echo "done\n";
?>
--EXPECT--
str_contains() expects exactly 2 arguments, 5 given
done
