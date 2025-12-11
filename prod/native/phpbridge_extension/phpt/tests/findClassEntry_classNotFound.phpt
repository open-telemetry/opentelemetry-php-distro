--TEST--
findClassEntry - class not found
--INI--
extension=/otel/phpbridge.so
--FILE--
<?php
declare(strict_types=1);

findClassEntry("unknownclassname");

echo 'Test completed';
?>
--EXPECTF--
%afindClassEntry found: 0
Test completed
