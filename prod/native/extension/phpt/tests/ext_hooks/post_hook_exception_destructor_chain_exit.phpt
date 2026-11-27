--TEST--
exit() in the destructor of an exception thrown by the destructor of an exception thrown by a post hook ends the script
--DESCRIPTION--
The exception of the post hook is released after it was logged, its destructor throws another exception, and the destructor of that one calls exit(). The exit() is not dropped, the policy for the post hooks is to keep it.
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
register_shutdown_function(fn() => print("shutdown\n"));

class Inner extends Exception
{
    public function __destruct()
    {
        echo "inner destructor: exit\n";
        exit;
    }
}

class Outer extends Exception
{
    public function __destruct()
    {
        echo "outer destructor\n";
        throw new Inner();
    }
}

function f() {
    echo "body\n";
}

\OpenTelemetry\Distro\hook(null, 'f', post: function () { echo "post hook\n"; throw new Outer(); });

f();
echo "after f\n";
?>
--EXPECT--
body
post hook
outer destructor
inner destructor: exit
shutdown
