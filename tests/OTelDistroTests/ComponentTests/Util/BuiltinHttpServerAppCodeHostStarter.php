<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OTelDistroTests\Util\Config\ConfigException;
use OTelDistroTests\Util\ExceptionUtil;
use OTelDistroTests\Util\FileUtil;
use Override;
use PHPUnit\Framework\Assert;

final class BuiltinHttpServerAppCodeHostStarter extends HttpServerStarter
{
    private const APP_CODE_HOST_ROUTER_SCRIPT = 'routeToBuiltinHttpServerAppCodeHost.php';

    private function __construct(
        private readonly HttpAppCodeHostParams $appCodeHostParams,
        ResourcesCleanerHandle $resourcesCleaner,
    ) {
        parent::__construct($appCodeHostParams->dbgProcessNamePrefix, $resourcesCleaner);
    }

    /**
     * @param int[] $portsInUse
     */
    public static function startBuiltinHttpServerAppCodeHost(HttpAppCodeHostParams $appCodeHostParams, ResourcesCleanerHandle $resourcesCleaner, array $portsInUse): HttpServerHandle
    {
        return (new self($appCodeHostParams, $resourcesCleaner))->startHttpServer(/* isTestScoped */ true, $portsInUse);
    }

    /** @inheritDoc */
    #[Override]
    protected function buildCommandLine(array $ports): string
    {
        Assert::assertCount(1, $ports);
        $routerScriptNameFullPath = FileUtil::partsToPath(__DIR__, self::APP_CODE_HOST_ROUTER_SCRIPT);
        if (!file_exists($routerScriptNameFullPath)) {
            throw new ConfigException(ExceptionUtil::buildMessage('Router script does not exist', compact('routerScriptNameFullPath')));
        }

        return InfraUtilForTests::buildAppCodePhpCmd()
               . ' -S ' . HttpServerHandle::SERVER_LOCALHOST_ADDRESS . ':' . $ports[0]
               . ' "' . $routerScriptNameFullPath . '"';
    }

    /** @inheritDoc */
    #[Override]
    protected function buildEnvVarsForSpawnedProcess(string $dbgProcessName, string $spawnedProcessInternalId, array $ports): array
    {
        Assert::assertCount(1, $ports);
        return InfraUtilForTests::addTestInfraDataPerProcessToEnvVars(
            $this->appCodeHostParams->buildEnvVarsForAppCodeProcess(),
            $spawnedProcessInternalId,
            $ports,
            $this->resourcesCleaner,
            $dbgProcessName
        );
    }
}
