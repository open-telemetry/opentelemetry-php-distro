<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

/**
 * @phpstan-import-type Pid from ProcessUtil
 */
final class StartedProcessStatus
{
    /**
     * @param Pid $pid
     */
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
