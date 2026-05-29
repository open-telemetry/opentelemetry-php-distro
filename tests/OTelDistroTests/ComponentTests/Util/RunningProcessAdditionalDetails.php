<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

/**
 * @phpstan-import-type Pid from RunningProcessesInfo
 */
final class RunningProcessAdditionalDetails
{
    /**
     * @phpstan-param Pid $parentPid
     */
    public function __construct(
        public readonly int $parentPid,
        public readonly string $state,
        public readonly string $commandLine,
    ) {
    }

    public function equals(RunningProcessAdditionalDetails $obj): bool
    {
        foreach (get_object_vars($this) as $propName => $thisPropValue) {
            if ($thisPropValue !== $obj->$propName) {
                return false;
            }
        }
        return true;
    }
}
