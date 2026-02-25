<?php

/**
 * @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection, PhpIllegalPsrClassPathInspection, PhpMultipleClassDeclarationsInspection
 */

declare(strict_types=1);

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 *
 * This attribute is used to indicate that a method is intended to override a method of a parent class or that it implements a method defined in an interface.
 *
 * If no method with the same name exists in a parent class or in an implemented interface a compile-time error will be emitted.
 *
 * @since PHP 8.3.0
 *
 * @link https://www.php.net/manual/en/class.override.php
 */
#[Attribute]
final class Override
{
}
