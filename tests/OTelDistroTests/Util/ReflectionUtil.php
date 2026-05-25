<?php

declare(strict_types=1);

namespace OTelDistroTests\Util;

use Closure;
use OpenTelemetry\Distro\Util\StaticClassTrait;
use OTelDistroTests\Util\Log\LoggableToString;
use ParseError;
use PHPUnit\Framework\Assert;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Traversable;

/**
 * @phpstan-type EnvVars array<string, string>
 */
final class ReflectionUtil
{
    use StaticClassTrait;

    public const ARRAY_TYPE_NAME = 'array';
    public const CALLABLE_TYPE_NAME = 'callable';
    public const FLOAT_TYPE_NAME = 'float';
    public const INT_TYPE_NAME = 'int';
    public const ITERABLE_TYPE_NAME = 'iterable';
    public const MIXED_TYPE_NAME = 'mixed';
    public const NULL_TYPE_NAME = 'null';
    public const OBJECT_TYPE_NAME = 'object';

    private const UNION_TYPE_MEMBERS_SEPARATOR = '|';

    public static function canonicalizeReflectionTypeName(string $name): string
    {
        if (!str_contains($name, self::UNION_TYPE_MEMBERS_SEPARATOR)) {
            return $name;
        }

        /** @var list<string> $members */
        $members = AssertEx::isArray(explode(self::UNION_TYPE_MEMBERS_SEPARATOR, $name));
        sort(/* ref */ $members, SORT_STRING);
        return implode(self::UNION_TYPE_MEMBERS_SEPARATOR, $members);
    }

    public static function getReflectionTypeCanonicalName(ReflectionType $type): string
    {
        return self::canonicalizeReflectionTypeName($type->__toString());
    }

    public static function areEquivalentReflectionTypeNames(string $name1, string $name2): bool
    {
        return self::canonicalizeReflectionTypeName($name1) === self::canonicalizeReflectionTypeName($name2);
    }

    public static function areEqualReflectionTypes(ReflectionType $type1, ReflectionType $type2): bool
    {
        return self::areEquivalentReflectionTypeNames($type1->__toString(), $type2->__toString());
    }

    private static function canBeAssignedToNamedTypes(ReflectionNamedType $source, ReflectionNamedType $target): bool
    {
        if (($sourceName = $source->getName()) === ($targetName = $target->getName())) {
            return true;
        }
        if (
            (($sourceName === self::INT_TYPE_NAME) && ($targetName === self::FLOAT_TYPE_NAME))
            || (($sourceName === self::ARRAY_TYPE_NAME) && ($targetName === self::ITERABLE_TYPE_NAME))
            || (($sourceName === self::NULL_TYPE_NAME) && $target->allowsNull())
        ) {
            return true;
        }

        if (!(class_exists($sourceName) || interface_exists($sourceName))) {
            return false;
        }
        if ($targetName === self::OBJECT_TYPE_NAME) {
            return true;
        }
        if (($sourceName === Closure::class) && ($targetName === self::CALLABLE_TYPE_NAME)) {
            return true;
        }
        if ($targetName === self::ITERABLE_TYPE_NAME) {
            return ($sourceName === Traversable::class) || is_subclass_of($sourceName, Traversable::class);
        }

        // Check class inheritance/interfaces
        return (class_exists($targetName) || interface_exists($targetName)) && is_subclass_of($sourceName, $targetName);
    }

    public static function canBeAssignedTo(ReflectionType $source, ReflectionType $target): bool
    {
        if (($target instanceof ReflectionNamedType) && ($target->getName() === self::MIXED_TYPE_NAME)) {
            return true;
        }

        // Handle nullability
        if ($source->allowsNull() && !$target->allowsNull()) {
            return false;
        }

        // Normalize types to arrays for easier comparison (PHP 8.0+)
        $sourceTypes = $source instanceof ReflectionUnionType ? $source->getTypes() : [$source];
        $targetTypes = $target instanceof ReflectionUnionType ? $target->getTypes() : [$target];

        // All provided types must be compatible with at least one target type
        foreach ($sourceTypes as $sourceType) {
            $foundMatch = false;
            foreach ($targetTypes as $targetType) {
                if (self::canBeAssignedToNamedTypes(AssertEx::isInstanceOf(ReflectionNamedType::class, $sourceType), AssertEx::isInstanceOf(ReflectionNamedType::class, $targetType))) {
                    $foundMatch = true;
                    break;
                }
            }
            if (!$foundMatch) {
                return false;
            }
        }

        return true;
    }

    /**
     * @template T
     *
     * @param Closure(T): void $closureWithTypeParam
     */
    public static function extractReflectionTypeFromClosureParam(Closure $closureWithTypeParam): ReflectionType
    {
        if (AmbientContextForTests::isInited()) {
            DebugContext::getCurrentScope(/* out */ $dbgCtx);
        } else {
            $dbgCtx = null;
        }

        $reflParams = (new ReflectionFunction($closureWithTypeParam))->getParameters();
        $dbgCtx?->add(compact('reflParams'));
        Assert::assertCount(1, $reflParams);
        $reflParam = ArrayUtilForTests::getSingleValue($reflParams);
        $dbgCtx?->add(compact('reflParam'));
        return AssertEx::notNull($reflParam->getType());
    }

    /**
     * @template T
     *
     * @param Closure(T): void $closureWithTypeParam
     */
    public static function extractReflectionTypeFromClosureParamAssertName(Closure $closureWithTypeParam, string $expectedTypeName): ReflectionType
    {
        if (AmbientContextForTests::isInited()) {
            DebugContext::getCurrentScope(/* out */ $dbgCtx);
        } else {
            $dbgCtx = null;
        }

        $type = self::extractReflectionTypeFromClosureParam($closureWithTypeParam);
        $actualTypeName = $type->__toString();
        $dbgCtx?->add(compact('actualTypeName'));
        Assert::assertTrue(self::areEquivalentReflectionTypeNames($expectedTypeName, $actualTypeName));
        return $type;
    }

    public static function buildReflectionType(string $typeAsString): ReflectionType
    {
        if (AmbientContextForTests::isInited()) {
            DebugContext::getCurrentScope(/* out */ $dbgCtx);
        } else {
            $dbgCtx = null;
        }
        $dummyClosure = AssertEx::opaqueAlwaysZero() === 0 ? null : (fn(int $_) => null);
        $codeToEvalToDefineDummyClosure = '$dummyClosure = (fn(' . $typeAsString . ' $_) => null);';
        $dbgCtx?->add(compact('codeToEvalToDefineDummyClosure'));
        try {
            eval($codeToEvalToDefineDummyClosure);
        } catch (ParseError $parseError) {
            Assert::fail(LoggableToString::convertMessageAndContext('eval () failed', compact('parseError')));
        }
        $dbgCtx?->add(['dummyClosure type' => get_debug_type($dummyClosure)]);
        Assert::assertNotNull($dummyClosure);
        return self::extractReflectionTypeFromClosureParamAssertName($dummyClosure, $typeAsString);
    }

    public static function getNullableReflectionTypeFor(ReflectionType $baseReflType): ReflectionType
    {
        return
            $baseReflType->allowsNull()
                ? $baseReflType
                : self::buildReflectionType($baseReflType instanceof ReflectionUnionType ? ($baseReflType . '|null') : ('?' . $baseReflType));
    }

    public static function boolReflectionType(): ReflectionType
    {
        return self::extractReflectionTypeFromClosureParamAssertName(fn(bool $_) => null, 'bool');
    }

    public static function nullableBoolReflectionType(): ReflectionType
    {
        return self::extractReflectionTypeFromClosureParamAssertName(fn(?bool $_) => null, '?bool');
    }

    public static function floatReflectionType(): ReflectionType
    {
        return self::extractReflectionTypeFromClosureParamAssertName(fn(float $_) => null, self::FLOAT_TYPE_NAME);
    }

    public static function nullableFloatReflectionType(): ReflectionType
    {
        return self::extractReflectionTypeFromClosureParamAssertName(fn(?float $_) => null, '?' . self::FLOAT_TYPE_NAME);
    }

    public static function intReflectionType(): ReflectionType
    {
        return self::extractReflectionTypeFromClosureParamAssertName(fn(int $_) => null, self::INT_TYPE_NAME);
    }

    public static function nullableIntReflectionType(): ReflectionType
    {
        return self::extractReflectionTypeFromClosureParamAssertName(fn(?int $_) => null, '?' . self::INT_TYPE_NAME);
    }

    public static function stringReflectionType(): ReflectionType
    {
        return self::extractReflectionTypeFromClosureParamAssertName(fn(string $_) => null, 'string');
    }

    public static function nullableStringReflectionType(): ReflectionType
    {
        return self::extractReflectionTypeFromClosureParamAssertName(fn(?string $_) => null, '?string');
    }
}
