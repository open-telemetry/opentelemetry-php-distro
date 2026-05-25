<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Config;

use OTelDistroTests\Util\ReflectionUtil;
use Override;
use ReflectionType;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 *
 * @extends NumericOptionParser<float>
 */
final class FloatOptionParser extends NumericOptionParser
{
    #[Override]
    protected function dbgValueTypeDesc(): string
    {
        return 'float';
    }

    #[Override]
    public static function isValidFormat(string $rawValue): bool
    {
        return filter_var($rawValue, FILTER_VALIDATE_FLOAT) !== false;
    }

    /** @inheritDoc */
    #[Override]
    protected function stringToNumber(string $rawValue): float
    {
        return floatval($rawValue);
    }

    #[Override]
    public function getParsedValueReflectionType(): ReflectionType
    {
        return ReflectionUtil::floatReflectionType();
    }
}
