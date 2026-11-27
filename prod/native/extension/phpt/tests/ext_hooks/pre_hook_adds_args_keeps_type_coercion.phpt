--TEST--
Pre hook adds arguments to a function whose default value is a constant expression, parameter types are still checked
--DESCRIPTION--
Evaluating the default of a skipped parameter runs on a fake frame of the function, its opline must be put back, otherwise the checks of the parameters before it are skipped.
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
class K
{
    const X = 'default';

    public function f(int $a, $b = self::X): void
    {
        var_dump($a, $b, func_num_args());
    }
}

\OpenTelemetry\Distro\hook(K::class, 'f', pre: fn() => [0 => '5', 2 => 'extra'], post: fn() => null);

(new K())->f('5');

try {
    \OpenTelemetry\Distro\hook(K::class, 'f', pre: fn() => [0 => 'not a number', 2 => 'extra']);
    (new K())->f(1);
} catch (TypeError $e) {
    echo "TypeError\n";
}
?>
--EXPECT--
int(5)
string(7) "default"
int(3)
TypeError
