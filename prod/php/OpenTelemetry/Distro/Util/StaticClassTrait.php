<?php

declare(strict_types=1);

namespace OpenTelemetry\Distro\Util;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
trait StaticClassTrait
{
    /**
     * Constructor is hidden because it's a "static" class
     */
    use HiddenConstructorTrait;
}
