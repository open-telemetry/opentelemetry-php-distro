--TEST--
Check if pre hook can modify named params of internal function
--SKIPIF--
<?php if (PHP_VERSION_ID < 80200) die('skip requires PHP >= 8.2'); ?>
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
OpenTelemetry\Distro\hook(
    null,
    'array_slice',
    pre: function(null $instance, array $params) {
        return [
          'length' => 1
        ];
    },
    post: fn() => null
);

var_dump(array_slice([1,2,3], 1, 2));
?>
--EXPECTF--
array(1) {
  [0]=>
  int(2)
}
