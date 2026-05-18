<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OTelDistroTests\Util\Config\ConfigException;
use OTelDistroTests\Util\ExceptionUtil;
use OTelDistroTests\Util\FileUtil;
use Override;

final class TestInfraHttpServerStarter extends HttpServerStarter
{
    private string $runScriptName;

    /**
     * @param int[] $portsInUse
     */
    public static function startTestInfraHttpServer(
        string $dbgProcessNamePrefix,
        string $runScriptName,
        array $portsInUse,
        int $portsToAllocateCount,
        ?ResourcesCleanerHandle $resourcesCleaner
    ): HttpServerHandle {
        return (new self($dbgProcessNamePrefix, $runScriptName, $resourcesCleaner))->startHttpServer(/* isTestScoped */ false, $portsInUse, $portsToAllocateCount);
    }

    private function __construct(string $dbgProcessNamePrefix, string $runScriptName, ?ResourcesCleanerHandle $resourcesCleaner)
    {
        parent::__construct($dbgProcessNamePrefix, $resourcesCleaner);

        $this->runScriptName = $runScriptName;
    }

    /** @inheritDoc */
    #[Override]
    protected function buildCommandLine(array $ports): string
    {
        $runScriptNameFullPath = FileUtil::partsToPath(__DIR__, $this->runScriptName);
        if (!file_exists($runScriptNameFullPath)) {
            throw new ConfigException(ExceptionUtil::buildMessage('Run script does not exist', array_merge(['runScriptName' => $this->runScriptName], compact('runScriptNameFullPath'))));
        }

        return 'php ' . '"' . FileUtil::partsToPath(__DIR__, $this->runScriptName) . '"';
    }

    /** @inheritDoc */
    #[Override]
    protected function buildEnvVarsForSpawnedProcess(string $dbgProcessName, string $spawnedProcessInternalId, array $ports): array
    {
        return InfraUtilForTests::buildEnvVarsForSpawnedProcessWithoutAppCode($dbgProcessName, $spawnedProcessInternalId, $ports, $this->resourcesCleaner);
    }
}
