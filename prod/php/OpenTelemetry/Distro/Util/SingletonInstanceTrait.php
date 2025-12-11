<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\Util;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
trait SingletonInstanceTrait
{
    /**
     * Constructor is hidden because instance() should be used instead
     */
    use HiddenConstructorTrait;

    private static ?self $singletonInstance = null;

    public static function singletonInstance(): self
    {
        if (self::$singletonInstance === null) {
            self::$singletonInstance = new static();
        }
        return self::$singletonInstance;
    }
}
