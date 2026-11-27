--TEST--
Check that SpanAttribute of an interface reaches a variadic implementation when the method name has upper case letters
--DESCRIPTION--
The function table of the interface is keyed by the lowercase name of the method.
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
include dirname(__DIR__) . '/mocks/SpanAttribute.php';

class WithSpanHandler
{
    public static function pre($object, array $params, ?string $class, string $function, ?string $filename, ?int $lineno, array $spanArgs, array $attributes): void
    {
        echo json_encode([$spanArgs, $attributes]), "\n";
    }

    public static function post(): void
    {
    }
}

interface I
{
    #[WithSpan('from.interface')]
    public function doWork(
        #[SpanAttribute('a')] string $x,
        #[SpanAttribute('b')] string $y,
    ): void;
}

class C implements I
{
    public function doWork(string ...$args): void
    {
    }
}

(new C())->doWork('X', 'Y');
?>
--EXPECT--
[{"name":"from.interface"},{"a":"X","b":"Y"}]
