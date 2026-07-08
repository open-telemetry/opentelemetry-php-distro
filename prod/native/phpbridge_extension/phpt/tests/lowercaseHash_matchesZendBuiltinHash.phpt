--TEST--
lowercaseHash produces the same value as PHP's actual built-in string hash for the lowercased bytes, across all zend_inline_hash_func unrolled-loop length boundaries
--INI--
extension=/otel/phpbridge.so
--FILE--
<?php
declare(strict_types=1);

$strings = [
    "",                                    // 0 bytes
    "a",                                   // 1
    "AB",                                  // 2
    "ABC",                                 // 3
    "ABCD",                                // 4
    "ABCDE",                               // 5
    "ABCDEF",                              // 6
    "ABCDEFG",                             // 7
    "ABCDEFGH",                            // 8 - first 8-byte chunk boundary
    "ABCDEFGHI",                           // 9 - 8-byte chunk + 1
    "ABCDEFGHIJKLMNO",                     // 15
    "ABCDEFGHIJKLMNOP",                    // 16 - two 8-byte chunks
    "ABCDEFGHIJKLMNOPQ",                   // 17 - two chunks + 1
    "TestClass",
    "userSpace",
    "Illuminate\\Foundation\\Application",
    "PDOStatement",
    "cURL_INIT",
];

foreach ($strings as $s) {
    $ours = lowercaseHash($s);
    $zend = zendBuiltinStringHash(strtolower($s));
    var_dump($ours === $zend);
}

echo 'Test completed';
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
Test completed
