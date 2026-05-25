<?php

declare(strict_types=1);

namespace OTelDistroTests\Util;

use OpenTelemetry\Distro\Util\StaticClassTrait;
use PHPUnit\Framework\Assert;

/**
 * @phpstan-type EnvVars array<string, string>
 */
final class EnvVarUtil
{
    use StaticClassTrait;

    public static function get(string $envVarName): ?string
    {
        $envVarValue = getenv($envVarName, /* local_only: */ true);
        return $envVarValue === false ? null : $envVarValue;
    }

    public static function set(string $envVarName, string $envVarValue): void
    {
        Assert::assertTrue(putenv($envVarName . '=' . $envVarValue));
        Assert::assertSame($envVarValue, self::get($envVarName));
    }

    public static function unset(string $envVarName): void
    {
        Assert::assertTrue(putenv($envVarName));
        Assert::assertNull(self::get($envVarName));
    }

    /** @noinspection PhpUnused */
    public static function setOrUnsetIfValueNull(string $envVarName, ?string $envVarValue): void
    {
        if ($envVarValue === null) {
            self::unset($envVarName);
        } else {
            self::set($envVarName, $envVarValue);
        }
    }
}
