<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use Ds\Map;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LoggableTrait;
use PHPUnit\Framework\Assert;

/**
 * @phpstan-import-type OptionsForProdMap from AppCodeHostParams
 */
final class TestMatrixRowOptionalPart implements LoggableInterface
{
    use LoggableTrait;

    public const KEY_VALUE_SEPARATOR = '=';

    /**
     * @param OptionsForProdMap $prodOptions
     */
    private function __construct(
        public readonly string $originalString,
        public readonly Map $prodOptions,
    ) {
    }

    public static function parse(string $stringToParse): self
    {
        // For example:
        //              OTEL_PHP_LOG_LEVEL_STDERR=debug,OTEL_PHP_LOG_LEVEL_SYSLOG=trace

        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $keyValParts = explode(TestMatrixRow::ROW_ELEMENTS_SEPARATOR, $stringToParse);
        $dbgCtx->add(compact('keyValParts'));

        /** @var OptionsForProdMap $prodOptions */
        $prodOptions = new Map();
        $dbgCtx->pushSubScope();
        foreach ($keyValParts as $keyValPart) {
            $dbgCtx->resetTopSubScope(compact('keyValPart'));
            $keyValueArr = explode(self::KEY_VALUE_SEPARATOR, $keyValPart, limit: 2);
            $dbgCtx->add(compact('keyValueArr'));
            Assert::assertCount(2, AssertEx::isArray($keyValueArr));
            $envVarName = $keyValueArr[0];
            $optName = AssertEx::notNull(OptionForProdName::tryToFindByEnvVarName($envVarName));
            Assert::assertFalse($prodOptions->hasKey($optName));
            $prodOptions->put($optName, $keyValueArr[1]);
        }
        $dbgCtx->popSubScope();

        return new self(originalString: $stringToParse, prodOptions: $prodOptions);
    }
}
