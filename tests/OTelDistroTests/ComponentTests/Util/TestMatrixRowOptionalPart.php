<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use Ds\Map;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\DebugContext;
use PHPUnit\Framework\Assert;

/**
 * @phpstan-import-type OptionsForProdMap from AppCodeHostParams
 */
final class TestMatrixRowOptionalPart
{
    /**
     * @param OptionsForProdMap $prodOptions
     */
    private function __construct(
        public readonly Map $prodOptions
    ) {
    }

    public static function parse(string $stringToParse): self
    {
        // For example:
        //              log_level_syslog=TRACE,scoped_deps_enabled=false

        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $keyValParts = explode(',', $stringToParse);
        $dbgCtx->add(compact('keyValParts'));

        /** @var OptionsForProdMap $result */
        $result = new Map();
        $dbgCtx->pushSubScope();
        foreach ($keyValParts as $keyValPart) {
            $dbgCtx->resetTopSubScope(compact('keyValPart'));
            $keyValueArr = explode('=', $keyValPart, limit: 2);
            $dbgCtx->add(compact('keyValueArr'));
            Assert::assertCount(2, AssertEx::isArray($keyValueArr));
            $result->put(OptionForProdName::findByName($keyValueArr[0]), $keyValueArr[1]);
        }
        $dbgCtx->popSubScope();

        return new self($result);
    }
}
