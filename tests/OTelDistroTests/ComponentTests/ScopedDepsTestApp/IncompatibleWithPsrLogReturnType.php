<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\ScopedDepsTestApp;

use Psr\Log\AbstractLogger;

/**
 * This class is not incompatible with versions of psr/log that have return type on its functions
 * because log() method below does not return type
 */
final class IncompatibleWithPsrLogReturnType extends AbstractLogger
{
    /** @noinspection PhpHierarchyChecksInspection */
    public function log($level, $message, array $context = [])
    {
    }
}
