--TEST--
Pre hook adds arguments beyond the declared parameters, with gaps
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'f', pre: fn() => [0 => 1, 1 => 2, 5 => 3], post: fn() => null);

function f($a) {
    var_dump(func_get_args());
}

f();
f('x', 'y', 'z');
echo "done\n";
?>
--EXPECT--
array(6) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  NULL
  [3]=>
  NULL
  [4]=>
  NULL
  [5]=>
  int(3)
}
array(6) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  string(1) "z"
  [3]=>
  NULL
  [4]=>
  NULL
  [5]=>
  int(3)
}
done