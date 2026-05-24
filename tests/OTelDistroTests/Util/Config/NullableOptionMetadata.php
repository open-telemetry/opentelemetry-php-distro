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
 * @template TParsedValue
 *
 * @extends OptionMetadata<TParsedValue>
 */
abstract class NullableOptionMetadata extends OptionMetadata
{
    /** @var OptionParser<TParsedValue> */
    private OptionParser $parser;

    /**
     * @param OptionParser<TParsedValue> $parser
     */
    public function __construct(OptionParser $parser)
    {
        $this->parser = $parser;
    }

    /** @inheritDoc */
    #[Override]
    public function parser(): OptionParser
    {
        return $this->parser;
    }

    /**
     * @inheritDoc
     *
     * @return null
     */
    #[Override]
    public function defaultValue(): mixed
    {
        return null;
    }

    #[Override]
    public function getParsedValueReflectionType(): ReflectionType
    {
        return ReflectionUtil::getNullableReflectionTypeFor(parent::getParsedValueReflectionType());
    }
}
