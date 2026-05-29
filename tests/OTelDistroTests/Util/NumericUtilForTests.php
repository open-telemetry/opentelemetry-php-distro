<?php

declare(strict_types=1);

namespace OTelDistroTests\Util;

use OpenTelemetry\Distro\Util\StaticClassTrait;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class NumericUtilForTests
{
    use StaticClassTrait;

    public static function compare(int|float $lhs, int|float $rhs): int
    {
        return ($lhs < $rhs) ? -1 : (($lhs == $rhs) ? 0 : 1);
    }

    /**
     * @template TNumber of int|float
     *
     * @param array<TNumber> $lhs
     * @param array<TNumber> $rhs
     *
     * @return int
     */
    public static function compareSequences(array $lhs, array $rhs): int
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        if (count($lhs) !== count($rhs)) {
            throw new InvalidArgumentException(ExceptionUtil::buildMessage('Sequences sizes do not match', compact('lhs', 'rhs')));
        }

        foreach (IterableUtil::zipWithIndex($lhs, $rhs) as [$index, $lhsElement, $rhsElement]) {
            /** @var TNumber $lhsElement */
            /** @var TNumber $rhsElement */
            $dbgCtx->add(compact('index', 'lhsElement', 'rhsElement'));
            if (($compareRetVal = self::compare($lhsElement, $rhsElement)) !== 0) {
                return $compareRetVal;
            }
        }
        return 0;
    }

    public static function isValidIntString(string $rawValue): bool
    {
        return filter_var($rawValue, FILTER_VALIDATE_INT) !== false;
    }

    public static function parseStringAsInt(string $rawValue): int
    {
        Assert::assertTrue(self::isValidIntString($rawValue));
        return intval($rawValue);
    }
}
