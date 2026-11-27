--TEST--
Check that exit(code) from a pre hook, which is dropped, does not change the exit status of the process
--DESCRIPTION--
exit(5) sets the exit status before it unwinds. The exit of a pre hook is dropped and the script goes on, so the status has to stay as it was.
--SKIPIF--
<?php if (!function_exists('exec')) die('skip requires exec()'); ?>
--FILE--
<?php
$inner = <<<'PHP'
<?php
\OpenTelemetry\Distro\hook(null, 'f', pre: function () { exit(5); });
function f() { echo "body\n"; }
f();
echo "end\n";
PHP;

$file = tempnam(sys_get_temp_dir(), 'otel_pre_exit_status_');
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
