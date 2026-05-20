<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use Closure;
use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\Log\LoggableTrait;
use OTelDistroTests\Util\Log\Logger;
use OTelDistroTests\Util\Log\LoggingSubsystem;
use PHPUnit\Framework\TestCase;
use Throwable;

abstract class SpawnedProcessBase implements LoggableInterface
{
    use LoggableTrait;

    public const FAILURE_PROCESS_EXIT_CODE = 213;
    public const DBG_PROCESS_NAME_ENV_VAR_NAME = 'OTEL_PHP_TESTS_DBG_PROCESS_NAME';

    private readonly Logger $logger;

    protected function __construct()
    {
        $this->logger = self::buildLogger()->addAllContext(compact('this'));

        $this->logger->logInfo(__FUNCTION__)
            ?->with(__LINE__, 'Finishing constructor...', ['test config' => AmbientContextForTests::testConfig(), 'environment variables' => EnvVarUtilForTests::getAll()]);
    }

    private static function buildLogger(): Logger
    {
        return AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__);
    }

    protected function processConfig(): void
    {
        AmbientContextForTests::testConfig()->verifyForSpawnedProcess();

        if ($this->shouldRegisterThisProcessWithResourcesCleaner()) {
            TestCase::assertNotNull(
                AmbientContextForTests::testConfig()->dataPerProcess()->resourcesCleanerSpawnedProcessInternalId,
                LoggableToString::convert(AmbientContextForTests::testConfig())
            );
            TestCase::assertNotNull(
                AmbientContextForTests::testConfig()->dataPerProcess()->resourcesCleanerPort,
                LoggableToString::convert(AmbientContextForTests::testConfig())
            );
        }
    }

    /**
     * @param Closure(SpawnedProcessBase): void $runImpl
     *
     * @throws Throwable
     */
    protected static function runSkeleton(Closure $runImpl): void
    {
        LoggingSubsystem::$isInTestingContext = true;

        try {
            $dbgProcessName = EnvVarUtilForTests::get(self::DBG_PROCESS_NAME_ENV_VAR_NAME);
            TestCase::assertIsString($dbgProcessName);

            AmbientContextForTests::init($dbgProcessName);

            $thisObj = new static(); // @phpstan-ignore new.static

            if (!$thisObj->shouldTracingBeEnabled()) {
                ConfigUtilForTests::verifyTracingIsDisabled();
            }

            $thisObj->processConfig();

            if ($thisObj->shouldRegisterThisProcessWithResourcesCleaner()) {
                $thisObj->registerWithResourcesCleaner();
            }

            $runImpl($thisObj);
        } catch (Throwable $throwable) {
            $level = LogLevel::critical;
            $isExpectedFromAppCode = false;
            $throwableToLog = $throwable;
            if ($throwable instanceof WrappedAppCodeException) {
                $isExpectedFromAppCode = true;
                $level = LogLevel::info;
                $throwableToLog = $throwable->wrappedException();
            }
            $logger = isset($thisObj) ? $thisObj->logger : self::buildLogger();
            $logger->logWithLevel(__FUNCTION__, $level)?->withThrowable(__LINE__, 'Throwable escaped to the top of the script', $throwableToLog, compact('isExpectedFromAppCode'));
            if ($isExpectedFromAppCode) {
                /** @noinspection PhpUnhandledExceptionInspection */
                throw $throwableToLog;
            } else {
                exit(self::FAILURE_PROCESS_EXIT_CODE);
            }
        }
    }

    protected function shouldTracingBeEnabled(): bool
    {
        return false;
    }

    protected function shouldRegisterThisProcessWithResourcesCleaner(): bool
    {
        return true;
    }

    protected function isThisProcessTestScoped(): bool
    {
        return false;
    }

    protected function registerWithResourcesCleaner(): void
    {
        $resourcesCleanerClient = new ResourcesCleanerClient(
            AssertEx::notNull(AmbientContextForTests::testConfig()->dataPerProcess()->resourcesCleanerSpawnedProcessInternalId),
            AssertEx::notNull(AmbientContextForTests::testConfig()->dataPerProcess()->resourcesCleanerPort),
        );
        $resourcesCleanerClient->registerProcessToTerminate(
            dbgProcessName: AmbientContextForTests::dbgProcessName(),
            pid: ProcessUtil::getCurrentPid(),
            isTestScoped: $this->isThisProcessTestScoped(),
        );
    }
}
