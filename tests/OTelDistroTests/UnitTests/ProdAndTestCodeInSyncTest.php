<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests;

use OpenTelemetry\Distro\PhpPartFacade;
use OpenTelemetry\Distro\Util\BoolUtil;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\BoolOptionParser;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\Config\ParseException;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\TestCaseBase;

class ProdAndTestCodeInSyncTest extends TestCaseBase
{
    public function testConfigOptionNamesInSync(): void
    {
        AssertEx::sameConstValues(PhpPartFacade::ENABLED_OPT_NAME, OptionForProdName::enabled->name);
        AssertEx::sameConstValues(PhpPartFacade::USER_BOOTSTRAP_PHP_FILE_OPT_NAME, OptionForProdName::user_bootstrap_php_file->name);
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
