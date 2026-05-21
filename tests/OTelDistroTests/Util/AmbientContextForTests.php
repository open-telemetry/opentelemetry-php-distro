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
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LoggerFactory;
use OTelDistroTests\Util\Log\LogStreamInterface;
use OTelDistroTests\Util\Log\SinkForTests;
use PHPUnit\Framework\Assert;

final class AmbientContextForTests implements LoggableInterface
{
    private static ?self $singletonInstance = null;

    private readonly SinkForTests $logSink;
    private readonly LogBackend $logBackend;
    private readonly LoggerFactory $loggerFactory;
    private readonly Clock $clock;
    private ConfigSnapshotForTests $testConfig;

    private function __construct(
        private readonly string $dbgProcessName,
    ) {
        $maxEnabledLogLevelBeforeRealConfig = LogLevel::error;
        $this->logSink = new SinkForTests($dbgProcessName);
        $this->logBackend = new LogBackend($maxEnabledLogLevelBeforeRealConfig, $this->logSink);
        $this->loggerFactory = new LoggerFactory($this->logBackend);
        $this->clock = new Clock($this->loggerFactory);

        // Reading and parsing config might call back to AmbientContextForTests singleton instance
        // so we should finish contructing it first and then read and parse actual config in init()
        $this->testConfig = self::buildDefaultConfig();
    }

    private static function buildDefaultConfig(): ConfigSnapshotForTests
    {
        $optNameToParsedValue = [];
        foreach (OptionForTestsName::cases() as $optName) {
            $optNameToParsedValue[$optName->name] = OptionsForTestsMetadata::get()[$optName->name]->defaultValue();
        }
        return new ConfigSnapshotForTests($optNameToParsedValue);
    }

    public static function init(string $dbgProcessName): void
    {
        ExceptionUtil::runCatchWriteToStdErrRethrow(
            function () use ($dbgProcessName): void {
                if (self::$singletonInstance !== null) {
                    Assert::assertSame(self::$singletonInstance->dbgProcessName, $dbgProcessName);
                    return;
                }

                self::$singletonInstance = new self($dbgProcessName);
                self::$singletonInstance->readAndApplyConfig();
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
                return AssertEx::notNull(self::$singletonInstance);
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

    public static function dbgProcessName(): string
    {
        return self::getSingletonInstance()->dbgProcessName;
    }

    public static function loggerFactory(): LoggerFactory
    {
        return self::getSingletonInstance()->loggerFactory;
    }

    public static function clock(): Clock
    {
        return self::getSingletonInstance()->clock;
    }

    public static function logSink(): SinkForTests
    {
        return self::getSingletonInstance()->logSink;
    }

    public function toLog(LogStreamInterface $stream): void
    {
        $stream->toLogAs(
            [
                'dbgProcessName' => $this->dbgProcessName,
                'testConfig' => $this->testConfig,
            ],
        );
    }
}
