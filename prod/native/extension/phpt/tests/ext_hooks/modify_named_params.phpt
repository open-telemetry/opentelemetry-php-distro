--TEST--
Check if pre hook can modify named params of function
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
OpenTelemetry\Distro\hook(
    null,
    'hello',
     function($obj, array $params) {
        return [
          'two' => 'replaced',
        ];
    }
);
function hello($one = null, $two = null, $three = null) {
  var_dump(func_get_args());
}

hello('a', 'b', 'c');
?>
--EXPECT--
array(3) {
  [0]=>
  string(1) "a"
  [1]=>
  string(8) "replaced"
  [2]=>
  string(1) "c"
}
