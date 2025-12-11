<?php

declare(strict_types=1);

error_reporting(E_ALL);

function phptOnAssertFailure(string $condDesc, string $expr, $actual, $expected)
{
    if ($expected === $actual) {
        return;
    }

    $indent = "\t\t\t\t\t\t";
    echo "========================================\n";
    echo "====================\n";
    echo "===\n";
    echo "\n";
    echo "Expected and actual values for:\n";
    echo "\n$indent";
    echo "$expr\n";
    echo "\n";
    echo "are not $condDesc.\n";
    echo "\n";
    echo "Expected value:\n";
    echo "\n$indent";
    var_dump($expected);
    echo "\n";
    echo "Actual value:\n";
    echo "\n$indent";
    var_dump($actual);
    echo "\n";
    echo "===\n";
    echo "====================\n";
    echo "========================================\n";
    die();
}

function phptAssertSame(string $expr, $actual, $expected)
{
    if ($expected === $actual) {
        return;
    }

    phptOnAssertFailure("the same", $expr, $actual, $expected);
}

/** @noinspection PhpUnused */
function phptAssertEqual(string $expr, $actual, $expected)
{
    if ($expected == $actual) {
        return;
    }

    phptOnAssertFailure("equal", $expr, $actual, $expected);
}

/** @noinspection PhpUnused */
function phptIsOsWindows(): bool
{
    return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
}

?>
