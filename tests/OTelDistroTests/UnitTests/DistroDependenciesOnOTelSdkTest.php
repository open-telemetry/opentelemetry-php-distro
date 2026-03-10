<?php

/** @noinspection PhpUnitMisorderedAssertEqualsArgumentsInspection */

declare(strict_types=1);

namespace OTelDistroTests\UnitTests;

use OTelDistroTests\Util\TestCaseBase;
use OpenTelemetry\API\Behavior\Internal\Logging as OTelInternalLogging;
use OpenTelemetry\SDK\Common\Configuration\Variables as OTelSdkConfigVariables;
use ReflectionClass;

final class DistroDependenciesOnOTelSdkTest extends TestCaseBase
{
    /**
     * @param class-string<object> $classFqName
     *
     * @noinspection PhpDocMissingThrowsInspection
     */
    private static function getPrivateConstValue(string $classFqName, string $constName): mixed
    {
        $reflClass = new ReflectionClass($classFqName);
        self::assertTrue($reflClass->hasConstant($constName));
        return $reflClass->getConstant($constName);
    }

    public function testLogLevelRelatedNames(): void
    {
        self::assertSame(self::getPrivateConstValue(OTelInternalLogging::class, 'OTEL_LOG_LEVEL'), OTelSdkConfigVariables::OTEL_LOG_LEVEL);
    }
}
