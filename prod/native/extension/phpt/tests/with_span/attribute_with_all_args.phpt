--TEST--
Check WithSpan attribute with name, span_kind, and attributes
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die('skip requires PHP >= 8.1'); ?>
--ENV--
OTEL_PHP_SCOPED_DEPS_ENABLED=false
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock_unscoped.inc
opentelemetry_distro.attr_hooks_enabled = On
--FILE--
<?php
namespace OpenTelemetry\API\Instrumentation;
include dirname(__DIR__) . '/mocks/WithSpan.php';
use OpenTelemetry\API\Instrumentation\WithSpan;

class WithSpanHandler
{
    public static function pre($obj, array $params, ?string $class, string $function, ?string $filename, ?int $lineno, array $attr_args, array $attributes): void
    {
        var_dump('pre');
        var_dump($attr_args);
        var_dump($attributes);
    }

    public static function post(): void
    {
        var_dump('post');
    }
}

#[WithSpan('my-span', 1, attributes: ['key' => 'value'])]
function foo(): void
{
    var_dump('test');
}

foo();
?>
--EXPECT--
string(3) "pre"
array(2) {
  ["name"]=>
  string(7) "my-span"
  ["span_kind"]=>
  int(1)
}
array(1) {
  ["key"]=>
  string(5) "value"
}
string(4) "test"
string(4) "post"
