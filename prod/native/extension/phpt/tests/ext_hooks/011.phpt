--TEST--
Check hooking namespaced function
--XFAIL--
leading backslash in the function name passed to hook() is not normalized, so the hook is registered under a different key
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
namespace Some\Namespace;

//with leading slash
\OpenTelemetry\Distro\hook(
    null,
    '\Some\Namespace\helloWorld',
    function(...$args) {
        var_dump($args);
    }
);

//without leading slash
\OpenTelemetry\Distro\hook(
    null,
    'Some\Namespace\helloWorld',
    function(...$args) {
        var_dump($args);
    }
);
function helloWorld() {
    return 42;
}

\Some\Namespace\helloWorld();
?>
--EXPECTF--
array(6) {
  [0]=>
  NULL
  [1]=>
  array(0) {
  }
  [2]=>
  NULL
  [3]=>
  string(25) "Some\Namespace\helloWorld"
  [4]=>
  string(%d) "%s011.php"
  [5]=>
  int(21)
}
array(8) {
  [0]=>
  NULL
  [1]=>
  array(0) {
  }
  [2]=>
  NULL
  [3]=>
  string(25) "Some\Namespace\helloWorld"
  [4]=>
  string(%d) "%s011.php"
  [5]=>
  int(21)
  [6]=>
  array(0) {
  }
  [7]=>
  array(0) {
  }
}
