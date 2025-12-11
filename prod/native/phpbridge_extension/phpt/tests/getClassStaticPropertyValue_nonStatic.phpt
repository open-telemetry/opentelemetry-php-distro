--TEST--
getClassStaticPropertyValue - non-static property
--INI--
extension=/otel/phpbridge.so
--FILE--
<?php
declare(strict_types=1);

class TestClass {
  public $testProperty = "hello";
}

var_dump(getClassStaticPropertyValue("testclass", "testProperty"));

echo 'Test completed';
?>
--EXPECTF--
%agetClassStaticPropertyValue property not found
NULL
Test completed
