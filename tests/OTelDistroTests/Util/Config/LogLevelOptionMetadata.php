<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Config;

use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\Util\ReflectionUtil;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 *
 * @extends OptionWithDefaultValueMetadata<LogLevel>
 */
final class LogLevelOptionMetadata extends OptionWithDefaultValueMetadata
{
    public function __construct(LogLevel $defaultValue)
    {
        parent::__construct(self::parserSingleton(), $defaultValue);
    }

    /**
     * @return EnumOptionParser<LogLevel>
     */
    public static function parserSingleton(): EnumOptionParser
    {
        /** @var ?EnumOptionParser<LogLevel> $result */
        static $result = null;
        if ($result === null) {
            $result = EnumOptionParser::useEnumCasesNames(
                LogLevel::class,
                parsedValueReflType: ReflectionUtil::extractReflectionTypeFromClosureParamAssertName(fn(LogLevel $_) => null, LogLevel::class),
                isCaseSensitive: false,
                isUnambiguousPrefixAllowed: true,
            );
        }
        return $result;
    }
}
