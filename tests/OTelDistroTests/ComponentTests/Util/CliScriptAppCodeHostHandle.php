<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use Closure;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\ClassNameUtil;
use OTelDistroTests\Util\Config\ConfigException;
use OTelDistroTests\Util\Config\OptionForTestsName;
use OTelDistroTests\Util\EnvVarUtil;
use OTelDistroTests\Util\ExceptionUtil;
use OTelDistroTests\Util\FileUtil;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\Logger;
use Override;
use PHPUnit\Framework\Assert;

/**
 * @phpstan-import-type EnvVars from EnvVarUtil
 */
final class CliScriptAppCodeHostHandle extends AppCodeHostHandle
{
    private readonly Logger $logger;

    /**
     * @param Closure(AppCodeHostParams): void $setParamsFunc
     */
    public function __construct(
        TestCaseHandle $testCaseHandle,
        Closure $setParamsFunc,
        private readonly ResourcesCleanerHandle $resourcesCleaner,
        string $dbgInstanceName
    ) {
        $this->logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__);
        $appCodeHostParams = new AppCodeHostParams(dbgProcessNamePrefix: ClassNameUtil::fqToShort(CliScriptAppCodeHost::class) . '_' . $dbgInstanceName);
        $appCodeHostParams->spawnedProcessInternalId = InfraUtilForTests::generateSpawnedProcessInternalId();
        $setParamsFunc($appCodeHostParams);

        parent::__construct($testCaseHandle, $appCodeHostParams);

        $this->logger->addAllContext(compact('this'));
    }

    public static function getRunScriptNameFullPath(): string
    {
        return FileUtil::partsToPath(__DIR__, CliScriptAppCodeHost::SCRIPT_TO_RUN_APP_CODE_HOST);
    }

    /** @inheritDoc */
    #[Override]
    public function execAppCode(AppCodeTarget $appCodeTarget, ?Closure $setParamsFunc = null): int
    {
        $localLogger = $this->logger->inherit()->addAllContext(compact('appCodeTarget'));
        $loggerProxyDebug = $localLogger->ifDebugLevelEnabledNoLine(__FUNCTION__);
        $requestParams = new AppCodeRequestParams($this->appCodeHostParams->spawnedProcessInternalId, $appCodeTarget);
        if ($setParamsFunc !== null) {
            $setParamsFunc($requestParams);
        }
        $localLogger->addAllContext(compact('requestParams'));

        $runScriptNameFullPath = self::getRunScriptNameFullPath();
        if (!file_exists($runScriptNameFullPath)) {
            throw new ConfigException(ExceptionUtil::buildMessage('Run script does not exist', compact('runScriptNameFullPath')));
        }

        $cmdLine = InfraUtilForTests::buildAppCodePhpCmd() . ' "' . $runScriptNameFullPath . '"';
        $localLogger->addAllContext(compact('cmdLine'));

        $dbgProcessName = DbgProcessNameGenerator::generate($this->appCodeHostParams->dbgProcessNamePrefix);
        $localLogger->addAllContext(compact('dbgProcessName'));

        $envVars = InfraUtilForTests::addTestInfraDataPerProcessToEnvVars(
            $this->appCodeHostParams->buildEnvVarsForAppCodeProcess(),
            $this->appCodeHostParams->spawnedProcessInternalId,
            [] /* <- targetServerPorts */,
            $this->resourcesCleaner,
            $dbgProcessName
        );
        $envVars[OptionForTestsName::data_per_request->toEnvVarName()] = PhpSerializationUtil::serializeToString($requestParams->dataPerRequest);
        ksort(/* ref */ $envVars);
        $localLogger->addAllContext(compact('envVars'));

        $loggerProxyDebug?->log(__LINE__, 'Executing app code ...');

        $appCodeInvocation = $this->beforeAppCodeInvocation($requestParams);
        $exitCode = $this->startProcessAndWaitForItToExit($dbgProcessName, $cmdLine, $envVars, $appCodeInvocation->appCodeRequestParams->dataPerRequest->expectedAppCodeProcessExitCode);
        $localLogger->addAllContext(compact('exitCode'));
        $this->afterAppCodeInvocation($appCodeInvocation);

        $loggerProxyDebug && $loggerProxyDebug->log(__LINE__, 'Executed app code');
        return $exitCode;
    }

    /**
     * @phpstan-param EnvVars $envVars
     */
    private function startProcessAndWaitForItToExit(string $dbgProcessName, string $command, array $envVars, ?int $expectedExitCode): int
    {
        $logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__);
        $logger->addAllContext(compact('dbgProcessName', 'command', 'envVars', 'expectedExitCode'));

        $procInfo = ProcessUtil::startProcessAndWaitForItToExit(
            dbgProcessName: $dbgProcessName,
            command: $command,
            envVars: $envVars,
            resourcesCleanerClient: $this->resourcesCleaner->getClient(),
            isTestScoped: true,
            maxWaitTimeInMicroseconds: 30 * 1000 * 1000 /* 30 seconds */,
        );
        $logger->addAllContext(compact('procInfo'));
        Assert::assertNotNull($procInfo->exitCode);

        if ($expectedExitCode !== null && ($procInfo->exitCode !== $expectedExitCode)) {
            throw new ComponentTestsInfraException(ExceptionUtil::buildMessage('Process exited with the unexpected exit code', $logger->getContext()));
        }

        return $procInfo->exitCode;
    }
}
