--TEST--
Hook of an internal method fires for a user subclass declared after hook() was called
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80200) {
    die('skip Internal function observers require PHP 8.2 or newer');
}
?>
--INI--
extension=/otel/opentelemetry_php_distro.so
opentelemetry_distro.bootstrap_php_part_file={PWD}/../includes/bootstrap_mock.inc
--FILE--
<?php

use function OpenTelemetry\Distro\hook;

$pre = $post = null;

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
hook(
    ArrayObject::class,
    'count',
    pre: static function ($receiver) use (&$pre): void {
        $pre = $receiver;
    },
);

// declared with eval() so that it is not bound at compile time, before hook() runs
eval('class HookDate extends DateTimeImmutable {} class HookArrayObject extends ArrayObject {}');

HookDate::createFromFormat('Y', '2020');
var_dump($pre === HookDate::class, $post === HookDate::class);

$collection = new HookArrayObject([1, 2]);
$collection->count();
var_dump($pre === $collection);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)