--TEST--
Check that an exit() in the destructor of a successfully evaluated argument stops the evaluation
--DESCRIPTION--
new Exiter() is evaluated without a failure, its destructor runs when the result is released and calls exit(). It is the last argument evaluated when the metadata is read, the attributes argument is evaluated on the call. Nothing may be evaluated after the exit().
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die("skip requires PHP >= 8.1"); ?>
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

class Exiter
{
    public function __destruct()
    {
        exit(1);
    }
}

// Must not run: it would mean an argument was evaluated after the exit().
spl_autoload_register(static function (string $class): void {
    if ($class === 'OpenTelemetry\\API\\Instrumentation\\Survivor') {
        echo "FAIL: evaluated an argument after exit()\n";
        eval('namespace OpenTelemetry\\API\\Instrumentation; class Survivor { const K = 3; }');
    }
});

class Subject
{
    #[WithSpan(new Exiter(), attributes: ['k' => Survivor::K])]
    public function m(): void
    {
    }
}

(new Subject())->m();
echo "after\n";
?>
--EXPECT--
[[],[]]
after
