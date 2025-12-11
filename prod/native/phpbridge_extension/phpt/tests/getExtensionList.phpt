--TEST--
getExtensionList
--INI--
extension=/otel/phpbridge.so
--FILE--
<?php
declare(strict_types=1);

getExtensionList();

echo 'Test completed';
?>
--EXPECTF--
%aname: 'Core' version:%a
%aname: 'otel_phpbridge' version:%a
Test completed
