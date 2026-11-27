--TEST--
Check that SpanAttribute on an unset property is read through __get() and that a throwing __get() is dropped
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
        echo get_class($object), ' ', json_encode($attributes), "\n";
    }

    public static function post(): void
    {
    }
}

class Lazy
{
    #[SpanAttribute]
    public string $id;

    public function __construct()
    {
        unset($this->id);
    }

    public function __get(string $name): string
    {
        return 'from __get ' . str_repeat('x', 3);
    }

    #[WithSpan]
    public function run(): void
    {
    }
}

class Throwing
{
    #[SpanAttribute]
    public string $id;

    public function __construct()
    {
        unset($this->id);
    }

    public function __get(string $name): string
    {
        throw new \RuntimeException('no id');
    }

    #[WithSpan]
    public function run(): void
    {
        echo "body\n";
    }
}

(new Lazy())->run();
(new Throwing())->run();
(new Throwing())->run();
echo "after\n";
?>
--EXPECT--
OpenTelemetry\API\Instrumentation\Lazy {"id":"from __get xxx"}
OpenTelemetry\API\Instrumentation\Throwing []
body
OpenTelemetry\API\Instrumentation\Throwing []
body
after
