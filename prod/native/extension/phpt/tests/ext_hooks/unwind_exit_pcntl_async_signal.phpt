--TEST--
Test UnwindExit caused by async pcntl handler is not suppressed
--EXTENSIONS--
pcntl
--SKIPIF--
<?php if (!extension_loaded("pcntl")) die("skip requires pcntl"); ?>
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php

use function OpenTelemetry\Distro\hook;

hook(
    null,
    'sleep',
    static fn() => null,
    static fn() => null,
);

pcntl_async_signals(true);
pcntl_signal(SIGALRM, static function() {
    echo "timeout\n";
    exit(1);
});

pcntl_alarm(1);
sleep(2);
echo "fail\n";
?>

--EXPECT--
timeout
