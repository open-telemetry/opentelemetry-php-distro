<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Config;

use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LoggableTrait;
use ReflectionType;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 *
 * @template TParsedValue
 */
abstract class OptionMetadata implements LoggableInterface
{
    use LoggableTrait;

    /**
     * @return OptionParser<TParsedValue>
     */
    abstract public function parser(): OptionParser;

    /**
     * @return null|TParsedValue
     */
    abstract public function defaultValue(): mixed;

    public function getParsedValueReflectionType(): ReflectionType
    {
        return $this->parser()->getParsedValueReflectionType();
    }
}
