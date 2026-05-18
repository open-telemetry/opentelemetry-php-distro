<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\ScopedDepsTestApp;

use Psr\Log\AbstractLogger;
use Stringable;

final class CompatibleWithPsrLogReturnType extends AbstractLogger
{
    public function log($level, Stringable|string $message, array $context = []): void
    {
    }
}
