--TEST--
Check that SpanAttribute on a private property of the parent is not read through __get() of the child
--DESCRIPTION--
The private property is not accessible from the method of the child, reading it would call __get() of the child (user code) and record what that returns.
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

class P
{
    #[SpanAttribute('parent_private')]
    private string $secret = 'parent secret';

    #[SpanAttribute('parent_protected')]
    protected string $shared = 'parent shared';
}

class C extends P
{
    #[SpanAttribute('own')]
    private string $mine = 'child own';

    public function __get(string $name): string
    {
        echo "__get($name)\n";
        return 'magic';
    }

    #[WithSpan]
    public function run(): void
    {
    }
}

(new C())->run();
(new C())->run();
?>
--EXPECT--
OpenTelemetry\API\Instrumentation\C {"own":"child own","parent_protected":"parent shared"}
OpenTelemetry\API\Instrumentation\C {"own":"child own","parent_protected":"parent shared"}
