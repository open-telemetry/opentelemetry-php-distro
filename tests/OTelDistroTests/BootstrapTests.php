<?php

/** @noinspection PhpUnused */

declare(strict_types=1);

namespace OTelDistroTests;

use OpenTelemetry\Distro\PhpPartFacade;
use OpenTelemetry\Distro\Util\StaticClassTrait;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\ExceptionUtil;
use OTelDistroTests\Util\Log\LoggableToJsonEncodable;
use OTelDistroTests\Util\Log\LoggingSubsystem;
use PHPUnit\Framework\Assert;

final class BootstrapTests
{
    use StaticClassTrait;

    private const TESTS_DBG_PROCESS_NAME = 'PHPUnit';

    public const LOG_COMPOSITE_DATA_MAX_DEPTH_IN_TEST_MODE = 15;

    private static function bootstrapShared(string $dbgProcessName): void
    {
        AmbientContextForTests::init($dbgProcessName);

        LoggingSubsystem::$isInTestingContext = true;
        LoggableToJsonEncodable::$maxDepth = self::LOG_COMPOSITE_DATA_MAX_DEPTH_IN_TEST_MODE;

        DebugContext::ensureInited();

        // PHP part of EDOT should not be loaded in the tests context
        Assert::assertFalse(PhpPartFacade::$wasBootstrapCalled);
    }

    public static function bootstrapTool(string $dbgProcessName): void
    {
        ExceptionUtil::runCatchWriteToStdErrRethrow(
            function () use ($dbgProcessName): void {
                self::bootstrapShared($dbgProcessName);
            }
        );
    }

    public static function bootstrapUnitTests(): void
    {
        ExceptionUtil::runCatchWriteToStdErrRethrow(
            function (): void {
                self::bootstrapShared(self::TESTS_DBG_PROCESS_NAME);
            }
        );
    }

    public static function bootstrapComponentTests(): void
    {
        ExceptionUtil::runCatchWriteToStdErrRethrow(
            function (): void {
                self::bootstrapShared(self::TESTS_DBG_PROCESS_NAME);
                AmbientContextForTests::testConfig()->verifyForComponentTests();
            }
        );
    }
}
