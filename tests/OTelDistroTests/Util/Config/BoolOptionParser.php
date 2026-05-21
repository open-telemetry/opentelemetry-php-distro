<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Config;

use Override;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 *
 * @extends EnumOptionParser<bool>
 */
final class BoolOptionParser extends EnumOptionParser
{
    /** @var list<string> */
    public static array $trueRawValues = ['true', 'yes', 'on', '1'];

    /** @var list<string> */
    public static array $falseRawValues = ['false', 'no', 'off', '0'];

    /** @var ?list<array{string, bool}> */
    private static ?array $boolNameToValue = null;

    public function __construct()
    {
        if (self::$boolNameToValue === null) {
            self::$boolNameToValue = [];
            foreach (self::$trueRawValues as $trueRawValue) {
                self::$boolNameToValue[] = [$trueRawValue, true];
            }
            foreach (self::$falseRawValues as $falseRawValue) {
                self::$boolNameToValue[] = [$falseRawValue, false];
            }
        }

        parent::__construct(dbgDesc: 'bool', nameValuePairs: self::$boolNameToValue, isCaseSensitive: false, isUnambiguousPrefixAllowed: false);
    }

    /** @inheritDoc */
    #[Override]
    public function parse(string $rawValue): bool
    {
        return $rawValue === '' ? false : parent::parse($rawValue);
    }
}
