--TEST--
hashClassAndFunctionNameLowercase matches the hash zend_observer computes for a real call, case-insensitively
--INI--
extension=/otel/phpbridge.so
--FILE--
<?php
declare(strict_types=1);

class TestClass {
    public function userSpace() {
        return getCurrentCallHash(1);
    }
}

$obj = new TestClass;
$realHash = $obj->userSpace();

// Same class/function, different case, computed with no class/function resolution at all -
// must equal the hash the observer dispatch path computed for the real, declared-case call.
var_dump($realHash === hashClassAndFunctionNameLowercase("TESTCLASS", "USERSPACE"));
var_dump($realHash === hashClassAndFunctionNameLowercase("testclass", "userspace"));
var_dump($realHash === hashClassAndFunctionNameLowercase("TestClass", "userSpace"));

// Sanity: a different name must not collide.
var_dump($realHash === hashClassAndFunctionNameLowercase("TestClass", "otherMethod"));
var_dump($realHash === hashClassAndFunctionNameLowercase("OtherClass", "userSpace"));

echo 'Test completed';
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
Test completed
