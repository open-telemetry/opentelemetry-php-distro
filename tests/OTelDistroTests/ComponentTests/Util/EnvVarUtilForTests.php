<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\Util\StaticClassTrait;
use OTelDistroTests\Util\EnvVarUtil;
use PHPUnit\Framework\Assert;

/**
 * @phpstan-import-type EnvVars from EnvVarUtil
 */
final class EnvVarUtilForTests
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

    public static function setOrUnset(string $envVarName, ?string $envVarValue): void
    {
        if ($envVarValue === null) {
            self::unset($envVarName);
        } else {
            self::set($envVarName, $envVarValue);
        }
    }

    /**
     * @param array<string, ?string> $envVarNameToOptValue
     *
     * @noinspection PhpUnused
     */
    public static function setOrUnsetForNames(array $envVarNameToOptValue): void
    {
        array_walk($envVarNameToOptValue, fn($envOptValue, $envVarName) => self::setOrUnset($envVarName, $envOptValue));
    }

    /**
     * @param array<string> $envVarNames
     *
     * @return array<string, ?string>
     *
     * @noinspection PhpUnused
     */
    public static function getForNames(array $envVarNames): array
    {
        $result = [];
        foreach ($envVarNames as $envVarName) {
            $result[$envVarName] = self::get($envVarName);
        }
        return $result;
    }

    /**
     * @return EnvVars
     */
    public static function getAll(): array
    {
        $result = getenv();
        ksort(/* ref */ $result);
        return $result;
    }
}
