<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests;

use OpenTelemetry\Distro\BootstrapStageLogger;
use OpenTelemetry\Distro\Log\LogLevel;
use OpenTelemetry\Distro\PhpPartFacade;
use OpenTelemetry\Distro\Util\BoolUtil;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\BoolOptionParser;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\Config\ParseException;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\TestCaseBase;
use ReflectionClass;

class ProdAndTestCodeInSyncTest extends TestCaseBase
{
    public function testProdAndTestCodeInSyncTest(): void
    {
        AssertEx::sameConstValues(PhpPartFacade::DEBUG_SCOPER_ENABLED_OPT_NAME, OptionForProdName::debug_scoper_enabled->name);
        AssertEx::sameConstValues(PhpPartFacade::ENABLED_OPT_NAME, OptionForProdName::enabled->name);
        AssertEx::sameConstValues(PhpPartFacade::USER_BOOTSTRAP_PHP_FILE_OPT_NAME, OptionForProdName::user_bootstrap_php_file->name);
    }

    public function testBootstrapStageLogger(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $bootstrapStageLoggerReflClass = new ReflectionClass(BootstrapStageLogger::class);

        // Verify that number of LEVEL_* consts in BootstrapStageLogger is the same as the number of cases in LogLevel
        $constsNameToVal = array_filter(
            $bootstrapStageLoggerReflClass->getConstants(),
            function (mixed $constVal, string $constName): bool {
                return str_starts_with($constName, 'LEVEL_') && is_int($constVal);
            },
            ARRAY_FILTER_USE_BOTH,
        );
        $dbgCtx->add(compact('constsNameToVal'));
        self::assertCount(count(LogLevel::cases()), $constsNameToVal);
        /** @var array<string, int> $constsNameToVal */

        // Verify each LEVEL_* const in BootstrapStageLogger has the same value as the correspinding case in LogLevel
        $dbgCtx->pushSubScope();
        foreach (LogLevel::cases() as $level) {
            $dbgCtx->resetTopSubScope(compact('level'));
            $constName = 'LEVEL_' . strtoupper($level->name);
            $dbgCtx->add(compact('constName'));
            self::assertTrue($bootstrapStageLoggerReflClass->hasConstant($constName));
            $constVal = $bootstrapStageLoggerReflClass->getConstant($constName);
            self::assertSame($level->value, $constVal);

            self::assertSame(strtoupper($level->name), BootstrapStageLogger::levelIntToString($constVal));

            self::assertSame($level->value, BootstrapStageLogger::levelStringToInt(strtoupper($level->name)));
            self::assertSame($level->value, BootstrapStageLogger::levelStringToInt(strtolower($level->name)));
        }
        $dbgCtx->popSubScope();

        // Verify strings generated for not predefined int values
        $maxPredefinedIntVal = max(AssertEx::notEmptyList(array_values($constsNameToVal)));
        foreach ([1, 12, 321, 4567] as $delta) {
            $notPredefinedLevelIntVal = $maxPredefinedIntVal + $delta;
            self::assertSame('LEVEL ' . $notPredefinedLevelIntVal, BootstrapStageLogger::levelIntToString($notPredefinedLevelIntVal));
            self::assertNull(BootstrapStageLogger::levelStringToInt(BootstrapStageLogger::levelIntToString($notPredefinedLevelIntVal)));
        }
    }

    public function testBoolOptionParser(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $boolOptionParser = new BoolOptionParser();
        $dbgCtx->pushSubScope();
        foreach ([[BoolOptionParser::$falseRawValues, false],[BoolOptionParser::$trueRawValues, true]] as [$rawValues, $expectedParsedValue]) {
            $dbgCtx->resetTopSubScope(compact('expectedParsedValue'));
            $dbgCtx->pushSubScope();
            foreach ($rawValues as $rawValue) {
                $dbgCtx->resetTopSubScope(compact('rawValue'));
                self::assertSame($expectedParsedValue, BoolUtil::parse($rawValue));
                self::assertSame($expectedParsedValue, $boolOptionParser->parse($rawValue));
                self::assertSame($expectedParsedValue, BoolUtil::parse(strtoupper($rawValue)));
                self::assertSame($expectedParsedValue, $boolOptionParser->parse(strtoupper($rawValue)));
            }
            $dbgCtx->popSubScope();
        }
        $dbgCtx->popSubScope();

        $assertThrowsParseException = function (callable $callable): void {
            $thrown = false;
            try {
                $callable();
            } /** @noinspection PhpUnusedLocalVariableInspection */ catch (ParseException $ex) {
                $thrown = true;
            }
            self::assertTrue($thrown);
        };

        $dbgCtx->pushSubScope();
        foreach (['invalid', 'value', '123', 'o', 't', 'f'] as $invalidRawValue) {
            $dbgCtx->resetTopSubScope(compact('invalidRawValue'));
            self::assertNull(BoolUtil::parse($invalidRawValue));
            $assertThrowsParseException(fn() => $boolOptionParser->parse($invalidRawValue));
        }
        $dbgCtx->popSubScope();
    }
}
