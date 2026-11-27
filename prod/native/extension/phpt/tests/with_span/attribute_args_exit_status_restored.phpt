--TEST--
Check that exit(code) swallowed while evaluating attribute arguments does not change the exit status
--DESCRIPTION--
exit(3) sets the exit status of the process before it unwinds. When the unwind is discarded the status has to be restored, otherwise the application goes on and still ends with status 3.
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die('skip requires PHP >= 8.1'); if (!function_exists('exec')) die('skip requires exec()'); ?>
--FILE--
<?php
$inner = <<<'PHP'
<?php
namespace OpenTelemetry\API\Instrumentation;

include $argv[1] . '/mocks/WithSpan.php';

class WithSpanHandler
{
    public static function pre(): void
    {
    }

    public static function post(): void
    {
    }
}

spl_autoload_register(static function (string $class): void {
    if ($class === 'OpenTelemetry\API\Instrumentation\Missing') {
        exit(3);
    }
});

#[WithSpan('x', Missing::KIND)]
function foo(): void
{
}

foo();
echo "end\n";
PHP;

$file = tempnam(sys_get_temp_dir(), 'otel_exit_status_');
file_put_contents($file, $inner);
$cmd = 'OTEL_PHP_SCOPED_DEPS_ENABLED=false ' . escapeshellarg(PHP_BINARY) . ' -n'
    . ' -d extension=/otel/opentelemetry_php_distro.so'
    . ' -d opentelemetry_distro.bootstrap_php_part_file=' . escapeshellarg(dirname(__DIR__) . '/includes/bootstrap_mock_unscoped.inc')
    . ' -d opentelemetry_distro.attr_hooks_enabled=On '
    . escapeshellarg($file) . ' ' . escapeshellarg(dirname(__DIR__)) . ' 2>&1';
exec($cmd, $output, $status);
unlink($file);
echo implode("\n", $output), "\n";
var_dump($status);
?>
--EXPECT--
end
int(0)
