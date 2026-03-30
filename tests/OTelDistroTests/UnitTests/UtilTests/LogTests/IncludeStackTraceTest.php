<?php

/** @noinspection PhpUnusedPrivateFieldInspection, PhpPrivateFieldCanBeLocalVariableInspection */

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests\LogTests;

use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\UnitTests\Util\MockLogPreformattedSink;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\ClassNameUtil;
use OTelDistroTests\Util\JsonUtil;
use OTelDistroTests\Util\Log\Backend as LogBackend;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\LoggableStackTrace;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\Log\Logger;
use OTelDistroTests\Util\Log\LoggerFactory;
use OTelDistroTests\Util\Log\LogLevelUtil;
use OTelDistroTests\Util\Log\SinkInterface as LogSinkInterface;
use OTelDistroTests\Util\StackTraceUtil;
use OTelDistroTests\Util\TestCaseBase;

class IncludeStackTraceTest extends TestCaseBase
{
    private static function buildLogger(LogSinkInterface $logSink): Logger
    {
        $loggerFactory = new LoggerFactory(new LogBackend(LogLevelUtil::getHighest(), $logSink));
        return $loggerFactory->loggerForClass(LogCategoryForTests::TEST, __NAMESPACE__, __CLASS__, __FILE__);
    }

    /**
     * @param Logger $logger
     *
     * @return array<string, mixed>
     */
    private static function includeStackTraceHelperFunc(Logger $logger): array
    {
        ($lgrPxy = $logger->ifTraceLevelEnabled(__LINE__, __FUNCTION__)) && $lgrPxy->includeStackTrace()->log('');
        $expectedSrcCodeLine = __LINE__ - 1;
        return [
            StackTraceUtil::FUNCTION_KEY => __FUNCTION__,
            StackTraceUtil::LINE_KEY     => $expectedSrcCodeLine,
        ];
    }

    /**
     * @param array<string, mixed> $expectedSrcCodeData
     * @param array<string, mixed> $actualFrame
     *
     * @return void
     */
    public static function verifyStackFrame(array $expectedSrcCodeData, array $actualFrame): void
    {
        $ctx = LoggableToString::convert(['$actualFrame' => $actualFrame]);
        self::assertCount(4, $actualFrame, $ctx);

        self::assertArrayHasKey(StackTraceUtil::FILE_KEY, $actualFrame, $ctx);
        $actualFilePath = $actualFrame[StackTraceUtil::FILE_KEY];
        self::assertIsString($actualFilePath, $ctx);
        self::assertSame(basename(__FILE__), basename($actualFilePath), $ctx);

        $expectedSrcCodeLine = $expectedSrcCodeData[StackTraceUtil::LINE_KEY];
        AssertEx::arrayHasKeyWithSameValue(StackTraceUtil::LINE_KEY, $expectedSrcCodeLine, $actualFrame, $ctx);

        self::assertArrayHasKey(StackTraceUtil::CLASS_KEY, $actualFrame, $ctx);
        $thisClassShortName = ClassNameUtil::fqToShort(__CLASS__);
        $actualClass = $actualFrame[StackTraceUtil::CLASS_KEY];
        self::assertIsString($actualClass, $ctx);
        /** @var class-string $actualClass */
        $actualClassShortName = ClassNameUtil::fqToShort($actualClass);
        self::assertSame($thisClassShortName, $actualClassShortName, $ctx);

        $expectedSrcCodeFunc = $expectedSrcCodeData[StackTraceUtil::FUNCTION_KEY];
        AssertEx::arrayHasKeyWithSameValue(StackTraceUtil::FUNCTION_KEY, $expectedSrcCodeFunc, $actualFrame, $ctx);
    }

    public function testIncludeStackTrace(): void
    {
        $mockLogSink = new MockLogPreformattedSink();
        $logger = self::buildLogger($mockLogSink);

        $expectedSrcCodeLineForThisFrame = __LINE__ + 1;
        $expectedSrcCodeDataForTopFrame = self::includeStackTraceHelperFunc($logger);

        self::assertCount(1, $mockLogSink->consumed);
        $actualLogStatement = $mockLogSink->consumed[0];
        self::assertSame(LogLevel::trace, $actualLogStatement->statementLevel);
        self::assertSame(LogCategoryForTests::TEST, $actualLogStatement->category);
        self::assertSame(__FILE__, $actualLogStatement->srcCodeFile);
        self::assertSame($expectedSrcCodeDataForTopFrame[StackTraceUtil::LINE_KEY], $actualLogStatement->srcCodeLine);
        self::assertSame(
            $expectedSrcCodeDataForTopFrame[StackTraceUtil::FUNCTION_KEY],
            $actualLogStatement->srcCodeFunc
        );

        $actualCtx = JsonUtil::decode($actualLogStatement->messageWithContext);
        self::assertIsArray($actualCtx);
        /** @var array<string, mixed> $actualCtx */
        AssertEx::arrayHasKeyWithSameValue(LogBackend::NAMESPACE_KEY, __NAMESPACE__, $actualCtx);
        self::assertArrayHasKey(LogBackend::CLASS_KEY, $actualCtx);
        $thisClassShortName = ClassNameUtil::fqToShort(__CLASS__);
        $actualFqClassName = $actualCtx[LogBackend::CLASS_KEY];
        self::assertSame($thisClassShortName, ClassNameUtil::fqToShort($actualFqClassName)); // @phpstan-ignore argument.type

        self::assertArrayHasKey(LoggableStackTrace::STACK_TRACE_KEY, $actualCtx);
        $actualStackTrace = $actualCtx[LoggableStackTrace::STACK_TRACE_KEY];
        self::assertIsArray($actualStackTrace);
        /** @var array<string, mixed>[] $actualStackTrace */
        AssertEx::countAtLeast(2, $actualStackTrace);
        self::verifyStackFrame($expectedSrcCodeDataForTopFrame, $actualStackTrace[0]);
        $expectedSrcCodeDataForThisFrame = [
            StackTraceUtil::FUNCTION_KEY => __FUNCTION__,
            StackTraceUtil::LINE_KEY     => $expectedSrcCodeLineForThisFrame,
        ];
        self::verifyStackFrame($expectedSrcCodeDataForThisFrame, $actualStackTrace[1]);
    }
}
