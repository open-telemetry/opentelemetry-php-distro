--TEST--
Check multiple hooks execute in correct order (pre: FIFO, post: LIFO)
--XFAIL--
post hooks run in registration order; instrumentation-ext runs them in reverse (LIFO)
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'hello',
    fn() => var_dump('pre-1'),
    fn() => var_dump('post-1')
);
\OpenTelemetry\Distro\hook(null, 'hello',
    fn() => var_dump('pre-2'),
    fn() => var_dump('post-2')
);
\OpenTelemetry\Distro\hook(null, 'hello',
    fn() => var_dump('pre-3'),
    fn() => var_dump('post-3')
);

function hello() {
    var_dump('CALL');
}

hello();
?>
--EXPECT--
string(5) "pre-1"
string(5) "pre-2"
string(5) "pre-3"
string(4) "CALL"
string(6) "post-3"
string(6) "post-2"
string(6) "post-1"
