<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

final class ProcessInfo
{
    public function __construct(
        public readonly int $pid,
        public readonly ?int $exitCode,
    ) {
    }

    public function hasExited(): bool
    {
        return $this->exitCode !== null;
    }
}
