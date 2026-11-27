--TEST--
Hook of an internal method fires for a user subclass that was bound before hook() was called
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80200) {
    die('skip Internal function observers require PHP 8.2 or newer');
}
?>
--XFAIL--
the class is early-bound at compile time and copies the internal method with its original handler; instrumentation-ext observes internal functions so it does not depend on the order
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