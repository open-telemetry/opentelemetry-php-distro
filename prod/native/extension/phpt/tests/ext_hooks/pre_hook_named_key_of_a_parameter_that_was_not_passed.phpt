--TEST--
Pre hook returns a named key of a parameter the caller did not pass, the parameter keeps its default
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
\OpenTelemetry\Distro\hook(null, 'hello', pre: fn() => ['two' => str_repeat('x', 10)], post: fn() => null);

function hello($one = null, $two = null) {
    var_dump($one, $two, func_num_args());
}

hello('a');
hello('a');
echo "done\n";
?>
--EXPECT--
string(1) "a"
NULL
int(1)
string(1) "a"
NULL
int(1)
done
