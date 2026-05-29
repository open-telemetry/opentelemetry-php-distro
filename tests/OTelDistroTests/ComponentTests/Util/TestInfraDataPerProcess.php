<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

/**
 * @phpstan-import-type Pid from ProcessUtil
 */
final class TestInfraDataPerProcess
{
    /**
     * @param Pid $phpUnitPid
     * @param int[] $thisServerPorts
     */
    public function __construct(
        public readonly int $phpUnitPid,
        public readonly ?string $resourcesCleanerServerId,
        public readonly ?int $resourcesCleanerPort,
        public readonly string $thisServerId,
        public readonly array $thisServerPorts,
    ) {
    }
}
