--TEST--
getClassStaticPropertyValue
--INI--
extension=/otel/phpbridge.so
--FILE--
<?php
declare(strict_types=1);

class TestClass {
  public static $testProperty = "hello";
}

var_dump(getClassStaticPropertyValue("testclass", "testProperty"));

echo 'Test completed';
?>
--EXPECTF--
string(5) "hello"
Test completed
