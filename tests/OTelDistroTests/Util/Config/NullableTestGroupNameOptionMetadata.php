<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Config;

use OTelDistroTests\ComponentTests\Util\TestGroupName;
use OTelDistroTests\Util\ReflectionUtil;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 *
 * @extends NullableOptionMetadata<TestGroupName>
 */
final class NullableTestGroupNameOptionMetadata extends NullableOptionMetadata
{
    public function __construct()
    {
        parent::__construct(
            EnumOptionParser::useEnumCasesNames(
                TestGroupName::class,
                parsedValueReflType: ReflectionUtil::extractReflectionTypeFromClosureParamAssertName(fn(TestGroupName $_) => null, TestGroupName::class),
                isCaseSensitive: true,
                isUnambiguousPrefixAllowed: false,
            )
        );
    }
}
