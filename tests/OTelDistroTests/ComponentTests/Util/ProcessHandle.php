<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\ExceptionUtil;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\Logger;
use OTelDistroTests\Util\Log\LogStreamInterface;

final class ProcessHandle implements LoggableInterface
{
    /** @var ?resource $procOpenRetVal */
    private mixed $procOpenRetVal;
    private ProcessInfo $lastInfo;
    private readonly Logger $logger;

    /**
     * @param resource $procOpenRetVal
     */
    public function __construct(
        private readonly string $dbgProcessName,
        mixed $procOpenRetVal,
    ) {
        $this->procOpenRetVal = $procOpenRetVal;
        $this->lastInfo = self::buildInfo($this->procOpenRetVal);
        $this->logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)->addAllContext(compact('this'));
    }

    /**
     * @param resource $procOpenRetVal
     */
    private static function buildInfo(mixed $procOpenRetVal): ProcessInfo
    {
        $procStatus = proc_get_status(AssertEx::notNull($procOpenRetVal));
        /** @noinspection PhpConditionAlreadyCheckedInspection */
        if (!is_array($procStatus)) { // @phpstan-ignore function.alreadyNarrowedType
            throw new ComponentTestsInfraException(ExceptionUtil::buildMessage('proc_get_status returned value which means an error', compact('procStatus')));
        }

        $pid = AssertEx::isInt($procStatus['pid']);
        $exitCode = AssertEx::isBool($procStatus['running']) ? null : AssertEx::isInt($procStatus['exitcode']);
        return new ProcessInfo($pid, $exitCode);
    }

    public function getCurrentInfo(): ProcessInfo
    {
        if (!($this->lastInfo->hasExited() || $this->isClosed())) {
            $this->lastInfo = self::buildInfo(AssertEx::notNull($this->procOpenRetVal));
        }
        return $this->lastInfo;
    }

    public function waitForProcessToExit(int $maxWaitTimeInMicroseconds, ?LogLevel $logLevelTimedout = null): bool
    {
        $logDebug = $this->logger->inherit()->addAllContext(compact('maxWaitTimeInMicroseconds'))->ifDebugLevelEnabledNoLine(__FUNCTION__);

        (new PollingCheck($this->dbgProcessName . ' exited', $maxWaitTimeInMicroseconds))->run(fn() => $this->getCurrentInfo()->hasExited());

        if ($this->getCurrentInfo()->hasExited()) {
            $logDebug?->log(__LINE__, 'Process exited');
        } else {
            $this->logger->ifLevelEnabled($logLevelTimedout ?? LogLevel::warning, __LINE__, __FUNCTION__)?->log('Wait for the started process to exit timed out');
        }

        return $this->getCurrentInfo()->hasExited();
    }

    public function close(): void
    {
        $procCloseRetVal = proc_close(AssertEx::notNull($this->procOpenRetVal));
        $this->procOpenRetVal = null;
        // For older versions of PHP (prior to 8.3.0), calling proc_get_status() after the process had already exited
        // would cause subsequent calls to proc_get_status() or proc_close() to return -1.
        // PHP 8.3.0 and newer: This behavior was corrected.
        // The process's exit code is now cached, and subsequent calls will return the correct, cached value.
        if (PHP_VERSION_ID >= 80300 && $procCloseRetVal === -1) {
            throw new ComponentTestsInfraException(ExceptionUtil::buildMessage('proc_close returned value which means an error', $this->logger->getContext()));
        }
    }

    public function isClosed(): bool
    {
        return $this->procOpenRetVal === null;
    }

    public function toLog(LogStreamInterface $stream): void
    {
        $stream->toLogAs(['lastInfo' => $this->lastInfo, 'is closed' => ($this->procOpenRetVal === null)]);
    }
}
