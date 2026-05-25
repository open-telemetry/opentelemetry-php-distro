<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Config;

use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LoggableTrait;
use ReflectionClass;
use ReflectionType;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 *
 * @template TParsedValue
 */
abstract class OptionParser implements LoggableInterface
{
    use LoggableTrait;

    /**
     * @return TParsedValue
     *
     * @throws ParseException
     */
    abstract public function parse(string $rawValue): mixed;

    public function getParsedValueReflectionType(): ReflectionType
    {
        return AssertEx::notNull((new ReflectionClass($this))->getMethod('parse')->getReturnType());
    }
}
