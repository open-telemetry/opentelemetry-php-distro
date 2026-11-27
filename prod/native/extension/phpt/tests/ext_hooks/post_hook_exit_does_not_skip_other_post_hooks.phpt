--TEST--
exit() in a post hook ends the script after the remaining post hooks have run
--DESCRIPTION--
The pre hook of every hook has run, so every post hook has to, it ends what the pre hook started (a span, a scope). The exit() is kept and ends the script afterwards.
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php
register_shutdown_function(fn() => print("shutdown\n"));

function f() {
    echo "body\n";
}

\OpenTelemetry\Distro\hook(null, 'f', pre: fn() => print("pre A\n"), post: fn() => print("post A\n"));
\OpenTelemetry\Distro\hook(null, 'f', pre: fn() => print("pre B\n"), post: function () { echo "post B: exit\n"; exit; });
\OpenTelemetry\Distro\hook(null, 'f', pre: fn() => print("pre C\n"), post: fn() => print("post C\n"));

f();
echo "after f\n";
?>
--EXPECT--
pre A
pre B
pre C
body
post C
post B: exit
post A
shutdown
