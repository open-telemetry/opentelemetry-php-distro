<?php

declare(strict_types=1);

namespace OTelDistroTests\Util;

use OpenTelemetry\Distro\Util\EnumUtilTrait;
use PHPUnit\Framework\Assert;

trait EnumUtilForTestsTrait
{
    use EnumUtilTrait;

    public static function findByName(string $enumName): self
    {
        $result = self::tryToFindByName($enumName);
        Assert::assertNotNull($result);
        return $result;
    }
}
