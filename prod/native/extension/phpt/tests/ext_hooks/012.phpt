--TEST--
Check hooking namespaced class with and without leading slash
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
namespace Some\Namespace;

//with leading slash
\OpenTelemetry\Distro\hook(
    '\Some\Namespace\HelloWorld',
    'sayHello',
    function(...$args) {
        var_dump($args);
    }
);

//without leading slash
\OpenTelemetry\Distro\hook(
    'Some\Namespace\HelloWorld',
    'sayHello',
    function(...$args) {
        var_dump($args);
    }
);

class HelloWorld {
    public static function sayHello() {
        return 42;
    }
}

\Some\Namespace\HelloWorld::sayHello();
?>
--EXPECTF--
array(6) {
  [0]=>
  string(25) "Some\Namespace\HelloWorld"
  [1]=>
  array(0) {
  }
  [2]=>
  string(25) "Some\Namespace\HelloWorld"
  [3]=>
  string(8) "sayHello"
  [4]=>
  string(%d) "%s012.php"
  [5]=>
  int(23)
}
array(6) {
  [0]=>
  string(25) "Some\Namespace\HelloWorld"
  [1]=>
  array(0) {
  }
  [2]=>
  string(25) "Some\Namespace\HelloWorld"
  [3]=>
  string(8) "sayHello"
  [4]=>
  string(%d) "%s012.php"
  [5]=>
  int(23)
}
