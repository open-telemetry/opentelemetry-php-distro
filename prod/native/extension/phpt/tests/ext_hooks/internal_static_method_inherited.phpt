--TEST--
Hook of an internal static method fires when it is called through a user subclass
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80200) {
    die('skip Internal function observers require PHP 8.2 or newer');
}
?>
--XFAIL--
hook on an internal method is not applied to the copy of the method that a user subclass inherits (instrumentation-ext observes internal functions, distro patches their handlers)
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php

use function OpenTelemetry\Distro\hook;

$pre = $post = null;

class HookDate extends DateTimeImmutable {}

hook(
    DateTimeImmutable::class,
    'createFromFormat',
    pre: static function ($receiver) use (&$pre): void {
        $pre = $receiver;
    },
    post: static function ($receiver) use (&$post): void {
        $post = $receiver;
    },
);

HookDate::createFromFormat('Y', '2020');
var_dump($pre === HookDate::class, $post === HookDate::class);
?>
--EXPECT--
bool(true)
bool(true)