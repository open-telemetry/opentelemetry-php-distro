--TEST--
Check that exit(code) raised while the default of a skipped parameter is evaluated does not change the exit status
--SKIPIF--
<?php if (!function_exists('exec')) die('skip requires exec()'); ?>
--FILE--
<?php
$inner = <<<'PHP'
<?php
spl_autoload_register(static function (string $class): void {
    if ($class === 'Missing') {
        exit(3);
    }
});
\OpenTelemetry\Distro\hook(null, 'g', pre: fn() => [0 => 'a', 2 => 'c']);
function g($a, $b = Missing::X, $c = null) { echo "body\n"; }
g();
echo "end\n";
PHP;

$file = tempnam(sys_get_temp_dir(), 'otel_default_exit_status_');
file_put_contents($file, $inner);
$cmd = escapeshellarg(PHP_BINARY) . ' -n'
    . ' -d extension=/otel/opentelemetry_php_distro.so'
    . ' -d opentelemetry_distro.bootstrap_php_part_file=' . escapeshellarg(dirname(__DIR__) . '/includes/bootstrap_mock.inc')
    . ' ' . escapeshellarg($file) . ' 2>&1';
exec($cmd, $output, $status);
unlink($file);
echo implode("\n", $output), "\n";
var_dump($status);
?>
--EXPECT--
body
end
int(0)
