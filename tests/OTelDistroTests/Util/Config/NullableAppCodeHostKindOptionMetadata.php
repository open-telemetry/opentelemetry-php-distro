<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Config;

use OTelDistroTests\ComponentTests\Util\AppCodeHostKind;
use OTelDistroTests\Util\ReflectionUtil;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 *
 * @extends NullableOptionMetadata<AppCodeHostKind>
 */
final class NullableAppCodeHostKindOptionMetadata extends NullableOptionMetadata
{
    public function __construct()
    {
        parent::__construct(
            EnumOptionParser::useEnumCasesNames(
                AppCodeHostKind::class,
                parsedValueReflType: ReflectionUtil::extractReflectionTypeFromClosureParamAssertName(fn(AppCodeHostKind $_) => null, AppCodeHostKind::class),
                isCaseSensitive: true,
                isUnambiguousPrefixAllowed: false,
            ),
        );
    }
}
