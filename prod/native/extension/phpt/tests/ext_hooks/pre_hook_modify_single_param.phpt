--TEST--
Check pre hook modifying a single positional parameter
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'hello',
    function($obj, array $params) {
        return ['modified'];
    }
);

function hello(string $val) {
    var_dump($val);
}

hello('original');
?>
--EXPECT--
string(8) "modified"
