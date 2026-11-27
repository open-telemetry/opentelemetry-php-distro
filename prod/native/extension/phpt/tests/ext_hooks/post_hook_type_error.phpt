--TEST--
Check if type error in post hook is handled
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
class Foo
{
  public function bar(): void
  {
    var_dump('bar');
  }
}

\OpenTelemetry\Distro\hook(
    Foo::class,
    'bar',
    fn() => var_dump('pre'),
    fn(string $scope, array $params, mixed $returnValue, ?Throwable $throwable) => var_dump('post')); //NB invalid type for $scope

(new Foo())->bar();
var_dump('baz');
--EXPECTF--
string(3) "pre"
string(3) "bar"
string(3) "baz"