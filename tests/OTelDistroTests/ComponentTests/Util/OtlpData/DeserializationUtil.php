<?php

/**
 * @noinspection PhpDeprecationInspection
 * Google\Protobuf\Internal\RepeatedField is deprecated, and Google\Protobuf\RepeatedField is used instead.
 */

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util\OtlpData;

use OpenTelemetry\Distro\Util\StaticClassTrait;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use Google\Protobuf\RepeatedField as ProtobufRepeatedField;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class DeserializationUtil
{
    use StaticClassTrait;

    /**
     * @template TSourceElementType
     * @template TResultElementType
     *
     * @param ProtobufRepeatedField<mixed> $source
     * @param callable(TSourceElementType): ?TResultElementType $deserializeElement
     *
     * @return TResultElementType[]
     */
    public static function deserializeArrayFromOTelProto(ProtobufRepeatedField $source, callable $deserializeElement): array
    {
        $logCtx = compact('source');
        $logCtx['source count'] = count($source);
        $logDebug = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)->addAllContext($logCtx)
            ->logDebug(__FUNCTION__);

        DebugContext::getCurrentScope(/* out */ $dbgCtx, $logCtx);

        $result = [];
        foreach ($source as $sourceElement) {
            $logDebug?->with(__LINE__, '', compact('sourceElement'));
            if (($resultElement = $deserializeElement($sourceElement)) === null) {
                continue;
            }
            $result[] = $resultElement;
        }
        return $result;
    }

    /**
     * @template TSource
     * @template TResult
     *
     * @param ?TSource $source
     * @param callable(TSource): TResult $deserialize
     *
     * @phpstan-return ?TResult
     */
    public static function deserializeNullableFromOTelProto(mixed $source, callable $deserialize): mixed
    {
        if ($source === null) {
            return null;
        }

        return $deserialize($source);
    }
}
