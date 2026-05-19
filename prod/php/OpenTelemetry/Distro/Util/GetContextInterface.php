<?php

declare(strict_types=1);

namespace OpenTelemetry\Distro\Util;

/**
 * @phpstan-type Context array<array-key, mixed>
 */
interface GetContextInterface
{
    /**
     * @return Context
     */
    public function getContext(): array;
}
