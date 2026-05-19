<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OTelDistroTests\Util\ArrayUtilForTests;
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
     * @param array<string, string> $prodOptionsEnvVarNameToRawVal
     */
    private function __construct(
        public readonly array $prodOptionsEnvVarNameToRawVal
    ) {
    }

    public static function parse(string $matrixRowOptionalPartAsString): self
    {
        // For example:
        //              OTEL_PHP_LOG_LEVEL_SYSLOG=TRACE,OTEL_PHP_SCOPED_DEPS_ENABLED=false

        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $keyValParts = explode(',', $matrixRowOptionalPartAsString);
        $dbgCtx->add(compact('keyValParts'));

        /** @var OptionsForProdMap $prodOptions */
        $prodOptionsEnvVarNameToRawVal = [];
        $dbgCtx->pushSubScope();
        foreach ($keyValParts as $keyValPart) {
            $dbgCtx->resetTopSubScope(compact('keyValPart'));
            $keyValueArr = explode('=', $keyValPart, limit: 2);
            $dbgCtx->add(compact('keyValueArr'));
            Assert::assertCount(2, AssertEx::isArray($keyValueArr));
            $envVarName = $keyValueArr[0];
            AssertEx::notNull(OptionForProdName::tryToFindByEnvVarName($envVarName));
            ArrayUtilForTests::addAssertingKeyNew(key: $envVarName, value: $keyValueArr[1], /* ref */ result: $prodOptionsEnvVarNameToRawVal);
        }
        $dbgCtx->popSubScope();

        return new self($prodOptionsEnvVarNameToRawVal);
    }
}
