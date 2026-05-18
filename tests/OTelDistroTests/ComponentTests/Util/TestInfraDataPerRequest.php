<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

final class TestInfraDataPerRequest
{
    /**
     * @param ?array<string, mixed> $appCodeRequestArgs
     */
    public function __construct(
        public readonly string $spawnedProcessInternalId,
        public readonly ?AppCodeTarget $appCodeTarget = null,
        public ?array $appCodeRequestArgs = null,
        public bool $isAppCodeExpectedToThrow = false,
    ) {
    }
}
