--TEST--
getPhpInfo
--INI--
extension=/otel/phpbridge.so
--FILE--
<?php
declare(strict_types=1);

getPhpInfo();

echo 'Test completed';
?>
--EXPECTF--
%aPHP-INFO: phpinfo()
PHP Version%a
%aotel_phpbridge%a
Test completed
