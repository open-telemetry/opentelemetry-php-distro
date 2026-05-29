<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use Ds\Set;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\ClassNameUtil;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\JsonUtil;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\Logger;
use OTelDistroTests\Util\TimeUtil;
use Override;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\TimerInterface;

/**
 * @phpstan-import-type Pid from ProcessUtil
 * @phpstan-import-type PidToDbgDesc from RunningProcessesInfo
 */
final class ResourcesCleaner extends TestInfraHttpServerProcessBase
{
    public const REGISTER_PROCESS_TO_TERMINATE_URI_PATH = TestInfraHttpServerProcessBase::BASE_URI_PATH . 'register_process_to_terminate';
    public const REGISTER_FILE_TO_DELETE_URI_PATH = TestInfraHttpServerProcessBase::BASE_URI_PATH . 'register_file_to_delete';

    public const DBG_PROCESS_NAME_HEADER_NAME = RequestHeadersRawSnapshotSource::HEADER_NAMES_PREFIX . 'DBG_PROCESS_NAME';
    public const PID_HEADER_NAME = RequestHeadersRawSnapshotSource::HEADER_NAMES_PREFIX . 'PID';
    public const IS_TEST_SCOPED_HEADER_NAME = RequestHeadersRawSnapshotSource::HEADER_NAMES_PREFIX . 'IS_TEST_SCOPED';
    public const PATH_HEADER_NAME = RequestHeadersRawSnapshotSource::HEADER_NAMES_PREFIX . 'PATH';

    public const MAX_WAIT_FOR_PROCESSES_TO_EXIT_AFTER_KILL = 30;
    public const MAX_WAIT_FOR_PROCESSES_TO_EXIT_AFTER_FORCE_KILL = 10;

    /** @var Set<string> */
    private Set $globalFilesToDeletePaths;

    /** @var Set<string> */
    private Set $testScopedFilesToDeletePaths;

    /** @var PidToDbgDesc */
    private array $globalProcessesToTerminate = [];

    /** @var PidToDbgDesc */
    private array $testScopedProcessesToTerminate = [];

    private ?TimerInterface $parentProcessTrackingTimer = null;

    private Logger $logger;

    public function __construct()
    {
        $this->globalFilesToDeletePaths = new Set();
        $this->testScopedFilesToDeletePaths = new Set();

        $this->logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)->addAllContext(compact('this'));

        parent::__construct();

        $this->logger->logDebug(__FUNCTION__)?->with(__LINE__, 'Done');
    }

    #[Override]
    protected function beforeLoopRun(): void
    {
        parent::beforeLoopRun();

        Assert::assertNotNull($this->reactLoop);
        $this->parentProcessTrackingTimer = $this->reactLoop->addPeriodicTimer(
            1 /* interval in seconds */,
            function () {
                $rootProcessId = AmbientContextForTests::testConfig()->dataPerProcess()->phpUnitPid;
                if (!ProcessUtil::doesProcessExist($rootProcessId)) {
                    $this->logger->logDebug(__FUNCTION__)?->with(__LINE__, 'Detected that parent process does not exist');
                    $this->exit();
                }
            }
        );
    }

    #[Override]
    protected function exit(): void
    {
        $this->terminateProcesses(isTestScopedOnly: false);
        $this->deleteFiles(isTestScopedOnly: false);

        Assert::assertNotNull($this->reactLoop);
        Assert::assertNotNull($this->parentProcessTrackingTimer);
        $this->reactLoop->cancelTimer($this->parentProcessTrackingTimer);

        parent::exit();
    }

    private function cleanTestScoped(): void
    {
        $this->terminateProcesses(isTestScopedOnly: true);
        $this->deleteFiles(isTestScopedOnly: true);
    }

    private function terminateProcesses(bool $isTestScopedOnly): bool
    {
        if ($isTestScopedOnly) {
            $retVal = $this->terminateProcessesFromSet(/* dbgProcessesSetDesc */ 'test scoped', $this->testScopedProcessesToTerminate);
            $this->testScopedProcessesToTerminate = [];
        } else {
            $retVal = $this->terminateProcessesFromSet(/* dbgProcessesSetDesc */ 'all', array_merge($this->testScopedProcessesToTerminate, $this->globalProcessesToTerminate));
            $this->testScopedProcessesToTerminate = [];
            $this->globalProcessesToTerminate = [];
        }
        return $retVal;
    }

    /**
     * @phpstan-param PidToDbgDesc $pidToDbgDesc
     */
    private function terminateProcessesFromSet(string $dbgProcessesSetDesc, array $pidToDbgDesc): bool
    {
        $logDebug = $this->logger->inherit()->addAllContext(compact('dbgProcessesSetDesc', 'pidToDbgDesc'))->logDebug(__FUNCTION__);
        $logDebug?->with(__LINE__, 'Entered');

        $runningProcesses = RunningProcessesInfo::getForAllInCurrentSession();
        $runningProcesses->getSubTrees(new Set(IterableUtil::keys($pidToDbgDesc)));

        $waitRetVal = false;
        foreach ([[false, self::MAX_WAIT_FOR_PROCESSES_TO_EXIT_AFTER_KILL], [true, self::MAX_WAIT_FOR_PROCESSES_TO_EXIT_AFTER_FORCE_KILL]] as [$force, $maxWaitTimeInSeconds]) {
            $runningProcesses->terminate($pidToDbgDesc, $force);
            $maxWaitTimeInMicroseconds = intval(TimeUtil::secondsToMicroseconds($maxWaitTimeInSeconds));
            if ($waitRetVal = $runningProcesses->waitToExit(ClassNameUtil::fqToShort(__CLASS__) . ' ' . $dbgProcessesSetDesc, maxWaitTimeInMicroseconds: $maxWaitTimeInMicroseconds)) {
                break;
            }
        }

        $logDebug?->with(__LINE__, 'Exiting', compact('waitRetVal'));
        return $waitRetVal;
    }

    private function deleteFiles(bool $isTestScopedOnly): void
    {
        $this->cleanFilesFrom(/* dbgFilesSetDesc */ 'test scoped', $this->testScopedFilesToDeletePaths);
        if (!$isTestScopedOnly) {
            $this->cleanFilesFrom(/* dbgFilesSetDesc */ 'global', $this->globalFilesToDeletePaths);
        }
    }

    /**
     * @param Set<string> $filesToDeletePaths
     */
    private function cleanFilesFrom(string $dbgFilesSetDesc, Set $filesToDeletePaths): void
    {
        $filesToDeletePathsCount = $filesToDeletePaths->count();
        $logDebug = $this->logger->logDebug(__FUNCTION__);
        $logDebug?->with(__LINE__, 'Deleting files...', compact('dbgFilesSetDesc', 'filesToDeletePathsCount'));

        foreach ($filesToDeletePaths as $fileToDeletePath) {
            if (!file_exists($fileToDeletePath)) {
                $logDebug?->with(__LINE__, 'File does not exist - so there is nothing to delete', compact('fileToDeletePath'));
                continue;
            }

            $unlinkRetVal = unlink($fileToDeletePath);
            $logDebug?->with(__LINE__, 'Called unlink() to delete file', compact('fileToDeletePath', 'unlinkRetVal'));
        }

        $filesToDeletePaths->clear();
    }

    /** @inheritDoc */
    #[Override]
    protected function processRequest(ServerRequestInterface $request): ?ResponseInterface
    {
        switch ($request->getUri()->getPath()) {
            case self::REGISTER_PROCESS_TO_TERMINATE_URI_PATH:
                $this->registerProcessToTerminate($request);
                break;
            case self::REGISTER_FILE_TO_DELETE_URI_PATH:
                $this->registerFileToDelete($request);
                break;
            case self::CLEAN_TEST_SCOPED_URI_PATH:
                $this->cleanTestScoped();
                break;
            default:
                return null;
        }
        return self::buildDefaultResponse();
    }

    protected function registerProcessToTerminate(ServerRequestInterface $request): void
    {
        $dbgProcessName = self::getRequiredRequestHeader($request, self::DBG_PROCESS_NAME_HEADER_NAME);
        $pid = AssertEx::stringIsInt(self::getRequiredRequestHeader($request, self::PID_HEADER_NAME));
        $isTestScoped = AssertEx::isBool(JsonUtil::decode(self::getRequiredRequestHeader($request, self::IS_TEST_SCOPED_HEADER_NAME)));
        if ($isTestScoped) {
            $this->testScopedProcessesToTerminate[$pid] = $dbgProcessName;
        } else {
            $this->globalProcessesToTerminate[$pid] = $dbgProcessName;
        }
        $this->logger->logDebug(__FUNCTION__)?->with(__LINE__, 'Successfully registered process to terminate', compact('pid', 'isTestScoped'));
    }

    protected function registerFileToDelete(ServerRequestInterface $request): void
    {
        $path = self::getRequiredRequestHeader($request, self::PATH_HEADER_NAME);
        $isTestScopedAsString = self::getRequiredRequestHeader($request, self::IS_TEST_SCOPED_HEADER_NAME);
        $isTestScoped = JsonUtil::decode($isTestScopedAsString);
        $filesToDeletePaths = $isTestScoped ? $this->testScopedFilesToDeletePaths : $this->globalFilesToDeletePaths;
        $filesToDeletePaths->add($path);
        $filesToDeletePathsCount = $filesToDeletePaths->count();
        $this->logger->logDebug(__FUNCTION__)?->with(__LINE__, 'Successfully registered file to delete', compact('path', 'isTestScoped', 'filesToDeletePathsCount'));
    }

    #[Override]
    protected function shouldRegisterThisProcessWithResourcesCleaner(): bool
    {
        return false;
    }
}
