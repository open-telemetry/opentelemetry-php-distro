--TEST--
exit() in a post hook ends the script when the exception of the function has a destructor that throws
--DESCRIPTION--
The exception that the function threw is saved while the post hook runs and released afterwards. When its destructor throws while an exit() is pending, PHP drops the exit(). The exit() must be kept.
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
register_shutdown_function(fn() => print("shutdown\n"));

class Thrower extends Exception
{
    public function __destruct()
    {
        echo "destructor throws\n";
        throw new RuntimeException('from the destructor');
    }
}

function f() {
    echo "body\n";
    throw new Thrower();
}

\OpenTelemetry\Distro\hook(null, 'f', post: function () { echo "post: exit\n"; exit; });

try {
    f();
} catch (Throwable $e) {
    echo "caught ", get_class($e), "\n";
}
echo "after\n";
?>
--EXPECT--
body
post: exit
destructor throws
shutdown
