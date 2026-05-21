<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OTelDistroTests\Util\EnumUtilForTestsTrait;

enum TestGroupName
{
    use EnumUtilForTestsTrait;

    case smoke;
    case does_not_require_external_services;
    case requires_external_services;

    public function doesRequireExternalServices(): bool
    {
        return match ($this) {
            self::smoke, self::requires_external_services => true,
            self::does_not_require_external_services => false,
        };
    }
}
