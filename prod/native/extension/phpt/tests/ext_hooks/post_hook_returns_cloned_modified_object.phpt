--TEST--
Check if post hook can returned modified clone
--DESCRIPTION--
A different object might be returned than the one provided to post hook. For example, PSR-7 messages are immutable and modifying
one creates a new instance.
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
class Foo
{
    public ?string $a = null;
    public function __construct(string|null $a = null)
    {
        $this->a = $a;
    }
    public function modify(string $value): Foo
    {
        $new = clone($this);
        $new->a = $value;

        return $new;
    }
}

\OpenTelemetry\Distro\hook(null, 'getFoo', null, function($obj, array $params, Foo $foo): Foo {
    return $foo->modify('b');
});

function getFoo(): Foo {
    return new Foo('a');
}

var_dump(getFoo());
?>
--EXPECTF--
object(Foo)#%d (1) {
  ["a"]=>
  string(1) "b"
}
