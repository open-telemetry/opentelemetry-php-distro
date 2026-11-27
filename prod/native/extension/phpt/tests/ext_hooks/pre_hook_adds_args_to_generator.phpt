--TEST--
Pre hook that returns more arguments than a generator was called with does not corrupt memory
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
function gen($a = null) {
    yield func_num_args();
    yield 'second';
}

\OpenTelemetry\Distro\hook(null, 'gen', pre: fn() => [0 => 'a', 1 => 'b', 2 => 'c'], post: fn() => null);

foreach (gen() as $value) {
    var_dump($value);
}
echo "done\n";
?>
--EXPECT--
int(1)
string(6) "second"
done
