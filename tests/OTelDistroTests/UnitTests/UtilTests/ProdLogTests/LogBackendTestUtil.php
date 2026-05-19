<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests\ProdLogTests;

use OpenTelemetry\Distro\Log\LogBackend;
use OpenTelemetry\Distro\Log\SourceCodeFilePathProcessor;

/**
 * @phpstan-import-type Context from LogBackend
 * @phpstan-import-type FormatAndWrite from LogBackend
 * @phpstan-import-type StringList from SourceCodeFilePathProcessor
 */
class LogBackendTestUtil
{
    /**
     * @phpstan-param LogBackend $tempInstance
     * @phpstan-param callable(): void $act
     */
    public static function saveActOnTempInstanceRestore(LogBackend $tempInstance, callable $act): void
    {
        $singletonInstanceToRestore = LogBackend::getSingletonInstance();
        LogBackend::resetSingletonInstance($tempInstance);

        try {
            ($act)();
        } finally {
            LogBackend::resetSingletonInstance($singletonInstanceToRestore);
        }
    }
}
