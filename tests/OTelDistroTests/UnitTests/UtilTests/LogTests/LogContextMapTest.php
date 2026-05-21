<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests\LogTests;

use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\UnitTests\Util\MockLogPreformattedSink;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\ClassNameUtil;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\JsonUtil;
use OTelDistroTests\Util\Log\LogBackendForTests as LogBackend;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\Logger;
use OTelDistroTests\Util\Log\LoggerFactory;
use OTelDistroTests\Util\Log\LogLevelUtil;
use OTelDistroTests\Util\Log\SinkInterface as LogSinkInterface;
use OTelDistroTests\Util\TestCaseBase;

class LogContextMapTest extends TestCaseBase
{
    private static function buildLogger(LogSinkInterface $logSink): Logger
    {
        $loggerFactory = new LoggerFactory(new LogBackend(LogLevelUtil::getHighest(), $logSink));
        return $loggerFactory->loggerForClass(LogCategoryForTests::TEST, __NAMESPACE__, __CLASS__, __FILE__);
    }

    public function testMergingContexts(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);
        $mockLogSink = new MockLogPreformattedSink();
        $dbgCtx->add(compact('mockLogSink'));
        $level1Ctx = ['level_1_key_1' => 'level_1_key_1 value', 'level_1_key_2' => 'level_1_key_2 value', 'some_key' => 'some_key level_1 value'];
        $loggerA = self::buildLogger($mockLogSink)->addAllContext($level1Ctx);
        $level2Ctx = ['level_2_key_1' => 'level_2_key_1 value', 'level_2_key_2' => 'level_2_key_2 value'];
        $loggerB = $loggerA->inherit()->addAllContext($level2Ctx);

        $logDebug = $loggerB->logDebug(__FUNCTION__);

        $level3Ctx = ['level_3_key_1' => 'level_3_key_1 value', 'level_3_key_2' => 'level_3_key_2 value', 'some_key' => 'some_key level_3 value'];
        $loggerB->addAllContext($level3Ctx);

        $stmtMsg = 'Some message';
        $stmtCtx = ['stmt_key_1' => 'stmt_key_1 value', 'stmt_key_2' => 'stmt_key_2 value'];
        $stmtLine = __LINE__ + 1;
        $logDebug?->with(__LINE__, $stmtMsg, $stmtCtx);

        $actualStmt = ArrayUtilForTests::getSingleValue($mockLogSink->consumed);

        self::assertSame(LogLevel::debug, $actualStmt->level);
        self::assertSame(LogCategoryForTests::TEST, $actualStmt->category);
        self::assertSame(__FILE__, $actualStmt->file);
        self::assertSame($stmtLine, $actualStmt->line);
        self::assertSame(__FUNCTION__, $actualStmt->func);

        self::assertSame($stmtMsg, $actualStmt->message);

        $actualCtx = JsonUtil::decode($actualStmt->contextAsString);
        self::assertIsArray($actualCtx);
        $expectedCtx = [
            'stmt_key_1' => 'stmt_key_1 value', 'stmt_key_2' => 'stmt_key_2 value',
            'level_3_key_1' => 'level_3_key_1 value', 'level_3_key_2' => 'level_3_key_2 value', 'some_key' => 'some_key level_3 value',
            'level_2_key_1' => 'level_2_key_1 value', 'level_2_key_2' => 'level_2_key_2 value',
            'level_1_key_1' => 'level_1_key_1 value', 'level_1_key_2' => 'level_1_key_2 value',
            LogBackend::NAMESPACE_KEY => __NAMESPACE__,
            LogBackend::CLASS_KEY => ClassNameUtil::fqToShort(__CLASS__),
        ];
        self::assertCount(count($expectedCtx), $actualCtx);
        foreach (IterableUtil::zip(IterableUtil::keys($expectedCtx), IterableUtil::keys($actualCtx)) as [$expectedKey, $actualKey]) {
            $dbgCtx->add(compact('expectedKey', 'actualKey'));
            AssertEx::ofArrayKeyType($expectedKey);
            AssertEx::ofArrayKeyType($actualKey);
            self::assertSame($expectedKey, $actualKey);
            self::assertSame($expectedCtx[$expectedKey], $actualCtx[$actualKey]);
        }

        $expectedCtxEncodedAsJson = JsonUtil::encode($expectedCtx);
        $dbgCtx->add(compact('expectedCtxEncodedAsJson'));
        self::assertSame($expectedCtxEncodedAsJson, $actualStmt->contextAsString);
    }
}
