<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\Log\LogLevel;
use OpenTelemetry\Distro\Util\StaticClassTrait;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\EnvVarUtil;
use OTelDistroTests\Util\ExceptionUtil;
use OTelDistroTests\Util\Log\LogCategoryForTests;

/**
 * @phpstan-import-type EnvVars from EnvVarUtil
 */
final class ProcessUtil
{
    use StaticClassTrait;

    public static function doesProcessExist(int $pid): bool
    {
        exec("ps -p $pid", /* out */ $cmdOutput, /* out */ $cmdExitCode);
        return $cmdExitCode === 0;
    }

    public static function waitForProcessToExitUsingPid(string $dbgProcessDesc, int $pid, int $maxWaitTimeInMicroseconds): bool
    {
        return (new PollingCheck(
            $dbgProcessDesc . ' process (PID: ' . $pid . ') exited' /* <- dbgDesc */,
            $maxWaitTimeInMicroseconds,
        ))->run(
            function () use ($pid): bool {
                return !self::doesProcessExist($pid);
            }
        );
    }

    public static function execCommandToTerminateProcess(int $pid, bool $force = false): bool
    {
        $logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)->addAllContext(compact('pid', 'force'));
        $logDebug = $logger->logDebug(__FUNCTION__);
        $shellCmd = 'kill ' . ($force ? '-9 ' : '') . $pid;
        $logger->addAllContext(compact('shellCmd'));
        $logDebug?->with(__LINE__, 'About to execute shell command');
        exec($shellCmd, /* ref */ $cmdOutput, /* ref */ $cmdExitCode);
        $logDebug?->with(__LINE__, 'Executed shell command', compact('cmdExitCode', 'cmdOutput'));
        return $cmdExitCode === 0;
    }

    public static function buildStdErrOutFileFullPath(string $dbgProcessName): ?string
    {
        if (AmbientContextForTests::testConfig()->logsDirectory === null) {
            return null;
        }

        return AmbientContextForTests::testConfig()->logsDirectory . DIRECTORY_SEPARATOR . $dbgProcessName . '_stderr_and_stdout.log';
    }

    private static function addStdErrOutRedirect(string $dbgProcessName, string $command): string
    {
        if (($stdErrOutFilePath = self::buildStdErrOutFileFullPath($dbgProcessName)) === null) {
            return $command;
        }

        $commandForBash = "set -e -o pipefail ; $command 2>&1 | tee \"$stdErrOutFilePath\"";
        return "bash -c \"$commandForBash\"";
    }

    /**
     * @phpstan-param EnvVars $envVars
     */
    public static function startBackgroundProcess(string $dbgProcessName, string $command, array $envVars, ?ResourcesCleanerClient $resourcesCleanerClient, bool $isTestScoped): void
    {
        $processHandle = self::procOpenEx(
            dbgProcessName: $dbgProcessName,
            command: self::addStdErrOutRedirect($dbgProcessName, $command) . '&',
            envVars: $envVars,
            isBackground: true,
            resourcesCleanerClient: $resourcesCleanerClient,
            isTestScoped: $isTestScoped
        );

        // Close handle to allow process to exit
        $processHandle->close();
    }

    /**
     * @phpstan-param EnvVars $envVars
     */
    public static function startProcessAndWaitForItToExit(
        string $dbgProcessName,
        string $command,
        array $envVars,
        ResourcesCleanerClient $resourcesCleanerClient,
        bool $isTestScoped,
        int $maxWaitTimeInMicroseconds,
        ?LogLevel $logLevelTimedout = null,
    ): ProcessInfo {
        $logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__);
        $logger->addAllContext(compact('dbgProcessName', 'command', 'envVars'));

        $processHandle = self::procOpenEx(
            dbgProcessName: $dbgProcessName,
            command: self::addStdErrOutRedirect($dbgProcessName, $command),
            envVars: $envVars,
            isBackground: false,
            resourcesCleanerClient: $resourcesCleanerClient,
            isTestScoped: $isTestScoped
        );
        $logger->addAllContext(compact('processHandle'));

        try {
            $processHandle->waitForProcessToExit($maxWaitTimeInMicroseconds, $logLevelTimedout);
            if (!$processHandle->getCurrentInfo()->hasExited()) {
                $logger->logWithLevel(__FUNCTION__, $logLevelTimedout ?? LogLevel::warning)?->with(__LINE__, 'Wait for the started process to exit timed out - terminating the process');
                self::execCommandToTerminateProcess(AssertEx::isInt($processHandle->getCurrentInfo()->pid));
            }
        } finally {
            $processHandle->close();
        }

        return $processHandle->getCurrentInfo();
    }

    /**
     * @phpstan-param EnvVars $envVars
     */
    private static function procOpenEx(string $dbgProcessName, string $command, array $envVars, bool $isBackground, ?ResourcesCleanerClient $resourcesCleanerClient, bool $isTestScoped): ProcessHandle
    {
        $logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__);
        $logger->addAllContext(compact('dbgProcessName', 'command', 'envVars', 'isBackground'));

        $logDebug = $logger->logDebug(__FUNCTION__);
        $logDebug?->with(__LINE__, "Starting process $dbgProcessName ($command) ...");

        $pipes = [];
        $procOpenRetVal = proc_open($command, /* descriptor_spec: */ [], /* ref */ $pipes, /* cwd: */ null, $envVars);
        $logger->addAllContext(compact('procOpenRetVal'));
        if ($procOpenRetVal === false) {
            $logger->logError(__FUNCTION__)?->with(__LINE__, 'Failed to start process');
            throw new ComponentTestsInfraException(ExceptionUtil::buildMessage('Failed to start process', $logger->getContext()));
        }

        $processHandle = new ProcessHandle($dbgProcessName, $procOpenRetVal);
        $resourcesCleanerClient?->registerProcessToTerminate($dbgProcessName, $processHandle->getCurrentInfo()->pid, $isTestScoped);

        $logInfo = $logger->logInfo(__FUNCTION__);
        $logInfo?->with(__LINE__, "Started process $dbgProcessName ($command)", compact('processHandle'));
        return $processHandle;
    }

    public static function getCurrentPid(): int
    {
        return AssertEx::isInt(getmypid());
    }
}
