--TEST--
Check declaring scope is null for non-class functions
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(
    null,
    'hello',
    function($obj, array $params, ?string $class, string $function) {
        var_dump($obj);
        var_dump($class);
    }
);

function hello(): void {}

hello();
?>
--EXPECT--
NULL
NULL
