<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\EnvVarUtil;
use OTelDistroTests\Util\ExceptionUtil;
use OTelDistroTests\Util\HttpMethods;
use OTelDistroTests\Util\HttpStatusCodes;
use OTelDistroTests\Util\JsonUtil;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\Log\LoggableTrait;
use OTelDistroTests\Util\Log\Logger;
use OTelDistroTests\Util\RandomUtil;
use OTelDistroTests\Util\RangeUtil;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * @phpstan-import-type EnvVars from EnvVarUtil
 * @phpstan-import-type Pid from ProcessUtil
 */
abstract class HttpServerStarter
{
    use LoggableTrait;

    private const PORTS_RANGE_BEGIN = 50000;
    public const PORTS_RANGE_END = 60000;

    private const MAX_WAIT_SERVER_START_MICROSECONDS = 10 * 1000 * 1000; // 10 seconds
    private const MAX_TRIES_TO_START_SERVER = 3;

    private readonly Logger $logger;

    protected function __construct(
        protected readonly string $dbgProcessNamePrefix,
        protected readonly ?ResourcesCleanerHandle $resourcesCleaner,
    ) {
        $this->logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)->addAllContext(compact('this'));
    }

    /**
     * @param int[] $ports
     */
    abstract protected function buildCommandLine(array $ports): string;

    /**
     * @param int[] $ports
     *
     * @return EnvVars
     */
    abstract protected function buildEnvVarsForSpawnedProcess(string $dbgProcessName, string $serverId, array $ports): array;

    /**
     * @param int[] $portsInUse
     */
    protected function startHttpServer(bool $isTestScoped, array $portsInUse, int $portsToAllocateCount = 1): HttpServerHandle
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        Assert::assertGreaterThanOrEqual(1, $portsToAllocateCount);
        /** @var ?int $lastTriedPort */
        $lastTriedPort = ArrayUtilForTests::isEmpty($portsInUse) ? null : ArrayUtilForTests::getLastValue($portsInUse);
        $dbgCtx->pushSubScope();
        foreach (RangeUtil::generateUpTo(self::MAX_TRIES_TO_START_SERVER) as $tryCount) {
            $dbgCtx->resetTopSubScope(compact('tryCount'));
            $dbgProcessName = DbgProcessNameGenerator::generate($this->dbgProcessNamePrefix);
            /** @var int[] $currentTryPorts */
            $currentTryPorts = [];
            self::findFreePortsToListen($portsInUse, $portsToAllocateCount, $lastTriedPort, /* out */ $currentTryPorts);
            Assert::assertSame($portsToAllocateCount, count($currentTryPorts));
            /**
             * We repeat $currentTryPorts type to fix PHPStan's
             * "Unable to resolve the template type T in call to method static method" error
             *
             * @phpstan-var int[] $currentTryPorts
             */
            $lastTriedPort = ArrayUtilForTests::getLastValue($currentTryPorts);
            $currentTryServerId = InfraUtilForTests::generateServerId();
            $cmdLine = $this->buildCommandLine($currentTryPorts);
            $envVars = $this->buildEnvVarsForSpawnedProcess($dbgProcessName, $currentTryServerId, $currentTryPorts);

            $logger = $this->logger->inherit()->addAllContext(
                array_merge(compact('dbgProcessName', 'tryCount', 'currentTryPorts', 'currentTryServerId', 'cmdLine', 'envVars'), ['maxTries' => self::MAX_TRIES_TO_START_SERVER])
            );
            $logDebug = $logger->logDebug(__FUNCTION__);

            $logDebug?->with(__LINE__, 'Starting HTTP server...');
            $startedProcessStatus = ProcessUtil::startBackgroundProcess($dbgProcessName, $cmdLine, $envVars, $this->resourcesCleaner?->getClient(), $isTestScoped);

            /** @var ?Pid $receivedPid */
            $receivedPid = null;
            if (!$this->isHttpServerRunning($dbgProcessName, $currentTryServerId, $currentTryPorts[0], $logger, /* ref */ $receivedPid)) {
                $runningProcessesInfo = RunningProcessesInfo::getForAllInCurrentSession();
                $dbgCtx->add(compact('runningProcessesInfo'));
                $logDebug?->with(__LINE__, 'Started HTTP server', compact('startedProcessStatus', 'receivedPid', 'runningProcessesInfo'));
                Assert::assertTrue($runningProcessesInfo->isDescendantOf($receivedPid, $startedProcessStatus->pid));
                return new HttpServerHandle($dbgProcessName, [$startedProcessStatus->pid, $receivedPid], $currentTryServerId, $currentTryPorts);
            }
            $logDebug?->with(__LINE__, 'Failed to start HTTP server');
        }
        $dbgCtx->popSubScope();
        throw new ComponentTestsInfraException(ExceptionUtil::buildMessage('Failed to start HTTP server', ['dbgProcessNamePrefix' => $this->dbgProcessNamePrefix]));
    }

    /**
     * @param int[]  $portsInUse
     * @param ?int   $lastTriedPort
     * @param int    $portsToFindCount
     * @param int[] &$result
     *
     * @return void
     */
    private static function findFreePortsToListen(
        array $portsInUse,
        int $portsToFindCount,
        ?int $lastTriedPort,
        array &$result
    ): void {
        $result = [];
        $lastTriedPortLocal = $lastTriedPort;
        foreach (RangeUtil::generateUpTo($portsToFindCount) as $ignored) {
            $foundPort = self::findFreePortToListen($portsInUse, $lastTriedPortLocal);
            $result[] = $foundPort;
            $lastTriedPortLocal = $foundPort;
        }
    }

    /**
     * @param int[] $portsInUse
     * @param ?int  $lastTriedPort
     *
     * @return int
     */
    private static function findFreePortToListen(array $portsInUse, ?int $lastTriedPort): int
    {
        $calcNextInCircularPortRange = function (int $port): int {
            return $port === (self::PORTS_RANGE_END - 1) ? self::PORTS_RANGE_BEGIN : ($port + 1);
        };

        $portToStartSearchFrom = $lastTriedPort === null
            ? RandomUtil::generateIntInRange(self::PORTS_RANGE_BEGIN, self::PORTS_RANGE_END - 1)
            : $calcNextInCircularPortRange($lastTriedPort);
        $candidate = $portToStartSearchFrom;
        while (true) {
            if (!in_array($candidate, $portsInUse)) {
                break;
            }
            $candidate = $calcNextInCircularPortRange($candidate);
            if ($candidate === $portToStartSearchFrom) {
                Assert::fail(LoggableToString::convertMessageAndContext('Could not find a free port', compact('portsInUse', 'portToStartSearchFrom')));
            }
        }
        return $candidate;
    }

    /**
     * @phpstan-param ?Pid &$receivedPid
     *
     * @param-out ?Pid $receivedPid
     *
     * @phpstan-assert-if-true Pid $receivedPid
     */
    private function isHttpServerRunning(string $dbgProcessName, string $serverId, int $port, Logger $logger, ?int &$receivedPid): bool
    {
        /** @var ?Throwable $lastThrown */
        $lastThrown = null;
        $dataPerRequest = new TestInfraDataPerRequest(serverId: $serverId);
        $checkResult = (new PollingCheck(
            $dbgProcessName . ' started',
            self::MAX_WAIT_SERVER_START_MICROSECONDS
        ))->run(
            function () use ($port, $dataPerRequest, $logger, &$lastThrown, &$receivedPid) {
                DebugContext::getCurrentScope(/* out */ $dbgCtx);

                try {
                    $response = HttpClientUtilForTests::sendRequest(
                        HttpMethods::GET,
                        new UrlParts(host: HttpServerHandle::CLIENT_LOCALHOST_ADDRESS, port: $port, path: HttpServerHandle::STATUS_CHECK_URI_PATH),
                        $dataPerRequest
                    );
                } catch (Throwable $throwable) {
                    $logger->logDebug(__FUNCTION__)?->withThrowable(__LINE__, 'Caught while checking if HTTP server is running', $throwable);
                    $lastThrown = $throwable;
                    return false;
                }

                if ($response->getStatusCode() !== HttpStatusCodes::OK) {
                    $logger->logDebug(__FUNCTION__)?->with(__LINE__, 'Received non-OK status code in response to status check', ['receivedStatusCode' => $response->getStatusCode()]);
                    return false;
                }

                $responseBody = $response->getBody()->getContents();
                $dbgCtx->add(compact('responseBody'));
                /** @var array<string, mixed> $responseBodyDecoded */
                $responseBodyDecoded = JsonUtil::decode($response->getBody()->getContents());
                $dbgCtx->add(compact('responseBodyDecoded'));
                $receivedPid = ProcessUtil::assertValidPid(AssertEx::arrayHasKey(HttpServerHandle::PID_KEY, $responseBodyDecoded));
                $logger->logDebug(__FUNCTION__)?->with(__LINE__, 'HTTP server status is OK', compact('receivedPid'));
                return true;
            }
        );

        if (!$checkResult) {
            if ($lastThrown === null) {
                $logger->logDebug(__FUNCTION__)?->with(__LINE__, 'Failed to send request to check HTTP server status');
            } else {
                $logger->logDebug(__FUNCTION__)?->withThrowable(__LINE__, 'Failed to send request to check HTTP server status', $lastThrown);
            }
        }

        return $checkResult;
    }
}
