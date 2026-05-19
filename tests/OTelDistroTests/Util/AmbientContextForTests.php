<?php

declare(strict_types=1);

namespace OTelDistroTests\Util;

use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\ComponentTests\Util\ConfigUtilForTests;
use OTelDistroTests\Util\Config\CompositeRawSnapshotSource;
use OTelDistroTests\Util\Config\ConfigSnapshotForTests;
use OTelDistroTests\Util\Config\EnvVarsRawSnapshotSource;
use OTelDistroTests\Util\Config\OptionForTestsName;
use OTelDistroTests\Util\Config\OptionsForTestsMetadata;
use OTelDistroTests\Util\Config\RawSnapshotSourceInterface;
use OTelDistroTests\Util\Log\LogBackendForTests as LogBackend;
use OTelDistroTests\Util\Log\LoggerFactory;
use OTelDistroTests\Util\Log\SinkForTests;
use PHPUnit\Framework\Assert;

final class AmbientContextForTests
{
    private static ?self $singletonInstance = null;
    private static ?string $dbgProcessName = null;
    private readonly SinkForTests $logSink;
    private readonly LogBackend $logBackend;
    private static ?LoggerFactory $loggerFactory = null;
    private readonly Clock $clock;
    private ConfigSnapshotForTests $testConfig;

    private function __construct(string $dbgProcessName)
    {
        self::$dbgProcessName = $dbgProcessName;
        $maxEnabledLogLevelBeforeRealConfig = LogLevel::error;
        $this->logSink = new SinkForTests($dbgProcessName);
        $this->logBackend = new LogBackend($maxEnabledLogLevelBeforeRealConfig, $this->logSink);
        self::$loggerFactory = new LoggerFactory($this->logBackend);
        $this->clock = new Clock(self::$loggerFactory);
        // Now that we have a logger, we can read real config and see the potential issues with it logged
        $this->readAndApplyConfig();
    }

    public static function init(string $dbgProcessName): void
    {
        ExceptionUtil::runCatchWriteToStdErrRethrow(
            function () use ($dbgProcessName): void {
                if (self::$singletonInstance !== null) {
                    Assert::assertSame(self::$dbgProcessName, $dbgProcessName);
                    return;
                }

                self::$singletonInstance = new self($dbgProcessName);
            }
        );
    }

    public static function isInited(): bool
    {
        return self::$singletonInstance !== null;
    }

    public static function assertIsInited(): void
    {
        ExceptionUtil::runCatchWriteToStdErrRethrow(
            function (): void {
                Assert::assertTrue(self::isInited(), 'Assertion that, ' . __CLASS__ . ' is initialized, failed');
            }
        );
    }

    private static function getSingletonInstance(): self
    {
        return ExceptionUtil::runCatchWriteToStdErrRethrow(
            function (): self {
                Assert::assertNotNull(self::$singletonInstance);
                return self::$singletonInstance;
            }
        );
    }

    public static function reconfigure(?RawSnapshotSourceInterface $additionalConfigSource = null): void
    {
        self::getSingletonInstance()->readAndApplyConfig($additionalConfigSource);
    }

    private function readAndApplyConfig(?RawSnapshotSourceInterface $additionalConfigSource = null): void
    {
        $envVarConfigSource = new EnvVarsRawSnapshotSource(OptionForTestsName::ENV_VAR_NAME_PREFIX, IterableUtil::keys(OptionsForTestsMetadata::get()));
        $configSource = $additionalConfigSource === null ? $envVarConfigSource : new CompositeRawSnapshotSource([$additionalConfigSource, $envVarConfigSource]);
        $this->testConfig = ConfigUtilForTests::read($configSource, self::loggerFactory());
        $this->logBackend->setMaxEnabledLevel($this->testConfig->logLevel);
    }

    public static function resetLogLevel(LogLevel $newVal): void
    {
        self::resetConfigOption(OptionForTestsName::log_level, $newVal->name);
        Assert::assertSame($newVal, AmbientContextForTests::testConfig()->logLevel);
    }

    public static function resetEscalatedRerunsMaxCount(int $newVal): void
    {
        self::resetConfigOption(OptionForTestsName::escalated_reruns_max_count, strval($newVal));
        Assert::assertSame($newVal, AmbientContextForTests::testConfig()->escalatedRerunsMaxCount);
    }

    private static function resetConfigOption(OptionForTestsName $optName, string $newValAsEnvVar): void
    {
        $envVarName = $optName->toEnvVarName();
        EnvVarUtil::set($envVarName, $newValAsEnvVar);
        AmbientContextForTests::reconfigure();
    }

    public static function testConfig(): ConfigSnapshotForTests
    {
        return self::getSingletonInstance()->testConfig;
    }

    /** @noinspection PhpUnused */
    public static function dbgProcessName(): string
    {
        return ExceptionUtil::runCatchWriteToStdErrRethrow(
            function (): string {
                Assert::assertNotNull(self::$dbgProcessName);
                return self::$dbgProcessName;
            }
        );
    }

    public static function loggerFactory(): LoggerFactory
    {
        return ExceptionUtil::runCatchWriteToStdErrRethrow(
            function (): LoggerFactory {
                Assert::assertNotNull(self::$loggerFactory);
                return self::$loggerFactory;
            }
        );
    }

    public static function clock(): Clock
    {
        return self::getSingletonInstance()->clock;
    }

    public static function logSink(): SinkForTests
    {
        return self::getSingletonInstance()->logSink;
    }
}
