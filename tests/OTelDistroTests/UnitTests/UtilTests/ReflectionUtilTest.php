<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests;

use Closure;
use Ds\Map as DsMap;
use Generator;
use Iterator;
use IteratorAggregate;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\ReflectionUtil;
use OTelDistroTests\Util\TestCaseBase;
use ReflectionNamedType;
use stdClass;
use Traversable;

class ReflectionUtilTest extends TestCaseBase
{
    public static function testCoreClasses(): void
    {
        /**
         * @param class-string<mixed> $maybeDerivedClass
         * @param class-string<mixed> $baseClass
         */
        $assertIsSubclassOf = function (string $maybeDerivedClass, string $baseClass, bool $expectedResult): void {
            self::assertSame($expectedResult, is_subclass_of($maybeDerivedClass, $baseClass));
        };
        $baseDerivedPairs = [
            [DummyBaseClassForTests::class, DummyDerivedClassForTests::class],
            [DummyBaseInterfaceForTests::class, DummyBaseClassForTests::class],
            [DummyBaseInterfaceForTests::class, DummyDerivedInterfaceForTests::class],
            [DummyBaseInterfaceForTests::class, DummyDerivedClassForTests::class],
            [Traversable::class, Iterator::class],
            [Traversable::class, IteratorAggregate::class],
        ];
        foreach ($baseDerivedPairs as [$baseClass, $derivedClass]) {
            $assertIsSubclassOf($baseClass, $derivedClass, false);
            $assertIsSubclassOf($derivedClass, $baseClass, true);
            $assertIsSubclassOf($baseClass, $baseClass, false);
            $assertIsSubclassOf($derivedClass, $derivedClass, false);
        }
    }

    public static function testBuildReflectionType(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $dbgCtx->pushSubScope();
        foreach (self::builtinTypeToNullable() as $baseType => $nullableType) {
            foreach ([$baseType, $nullableType] as $type) {
                $dbgCtx->resetTopSubScope(compact('type'));
                $buildResult = ReflectionUtil::buildReflectionType($type->__toString());
                self::assertSame($type->__toString(), $buildResult->__toString());
            }
        }
        $dbgCtx->popSubScope();
    }

    private static function arrayReflectionType(): DsHashableReflectionType
    {
        /**
         * @param array<mixed> $_
         */
        $closureWithTypeParam = fn(array $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, ReflectionUtil::ARRAY_TYPE_NAME));
    }

    private static function nullableArrayReflectionType(): DsHashableReflectionType
    {
        /**
         * @param ?array<mixed> $_
         */
        $closureWithTypeParam = fn(?array $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, '?' . ReflectionUtil::ARRAY_TYPE_NAME));
    }

    private static function boolReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::boolReflectionType());
    }

    private static function nullableBoolReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::nullableBoolReflectionType());
    }

    private static function callableReflectionType(): DsHashableReflectionType
    {
        /**
         * @param callable(): void $_
         */
        $closureWithTypeParam = fn(callable $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, ReflectionUtil::CALLABLE_TYPE_NAME));
    }

    private static function nullableCallableReflectionType(): DsHashableReflectionType
    {
        /**
         * @param ?callable(): void $_
         */
        $closureWithTypeParam = fn(?callable $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, '?' . ReflectionUtil::CALLABLE_TYPE_NAME));
    }

    private static function closureReflectionType(): DsHashableReflectionType
    {
        /**
         * @param Closure(): void $_
         */
        $closureWithTypeParam = fn(Closure $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, Closure::class));
    }

    private static function nullableClosureReflectionType(): DsHashableReflectionType
    {
        /**
         * @param ?Closure(): void $_
         */
        $closureWithTypeParam = fn(?Closure $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, '?' . Closure::class));
    }

    private static function dsMapReflectionType(): DsHashableReflectionType
    {
        /**
         * @param DsMap<mixed, mixed> $_
         */
        $closureWithTypeParam = fn(DsMap $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, DsMap::class));
    }

    private static function nullableDsMapReflectionType(): DsHashableReflectionType
    {
        /**
         * @param ?DsMap<mixed, mixed> $_
         */
        $closureWithTypeParam = fn(?DsMap $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, '?' . DsMap::class));
    }

    private static function dummyBaseClassForTestsReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(DummyBaseClassForTests $_) => null, DummyBaseClassForTests::class));
    }

    private static function nullableDummyBaseClassForTestsReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(?DummyBaseClassForTests $_) => null, '?' . DummyBaseClassForTests::class));
    }

    private static function dummyBaseInterfaceForTestsReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(DummyBaseInterfaceForTests $_) => null, DummyBaseInterfaceForTests::class));
    }

    private static function nullableDummyBaseInterfaceForTests(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(?DummyBaseInterfaceForTests $_) => null, '?' . DummyBaseInterfaceForTests::class));
    }

    private static function dummyDerivedClassForTestsReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(DummyDerivedClassForTests $_) => null, DummyDerivedClassForTests::class));
    }

    private static function nullableDummyDerivedClassForTestsReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(?DummyDerivedClassForTests $_) => null, '?' . DummyDerivedClassForTests::class));
    }

    private static function dummyDerivedInterfaceForTestsReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(DummyDerivedInterfaceForTests $_) => null, DummyDerivedInterfaceForTests::class));
    }

    private static function nullableDummyDerivedInterfaceForTestsReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(?DummyDerivedInterfaceForTests $_) => null, '?' . DummyDerivedInterfaceForTests::class));
    }

    private static function floatReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::floatReflectionType());
    }

    private static function nullableFloatReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::nullableFloatReflectionType());
    }

    private static function generatorReflectionType(): DsHashableReflectionType
    {
        /**
         * @param Generator<mixed> $_
         */
        $closureWithTypeParam = fn(Generator $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, Generator::class));
    }

    private static function nullableGeneratorReflectionType(): DsHashableReflectionType
    {
        /**
         * @param ?Generator<mixed> $_
         */
        $closureWithTypeParam = fn(?Generator $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, '?' . Generator::class));
    }

    private static function intReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::intReflectionType());
    }

    private static function nullableIntReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::nullableIntReflectionType());
    }

    private static function iteratorReflectionType(): DsHashableReflectionType
    {
        /**
         * @param Iterator<mixed> $_
         */
        $closureWithTypeParam = fn(Iterator $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, Iterator::class));
    }

    private static function nullableIteratorReflectionType(): DsHashableReflectionType
    {
        /**
         * @param ?Iterator<mixed> $_
         */
        $closureWithTypeParam = fn(?Iterator $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, '?' . Iterator::class));
    }

    private static function iteratorAggregateReflectionType(): DsHashableReflectionType
    {
        /**
         * @param IteratorAggregate<mixed> $_
         */
        $closureWithTypeParam = fn(IteratorAggregate $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, IteratorAggregate::class));
    }

    private static function nullableIteratorAggregateReflectionType(): DsHashableReflectionType
    {
        /**
         * @param ?IteratorAggregate<mixed> $_
         */
        $closureWithTypeParam = fn(?IteratorAggregate $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, '?' . IteratorAggregate::class));
    }

    private static function iterableReflectionType(): DsHashableReflectionType
    {
        /**
         * @param iterable<mixed> $_
         */
        $closureWithTypeParam = fn(iterable $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, ReflectionUtil::ITERABLE_TYPE_NAME));
    }

    private static function nullableIterableReflectionType(): DsHashableReflectionType
    {
        /**
         * @phpstan-param ?iterable<mixed> $_
         */
        $closureWithTypeParam = fn(?iterable $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, '?' . ReflectionUtil::ITERABLE_TYPE_NAME));
    }

    private static function mixedReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(mixed $_) => null, ReflectionUtil::MIXED_TYPE_NAME));
    }

    private static function objectReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(object $_) => null, ReflectionUtil::OBJECT_TYPE_NAME));
    }

    private static function nullableObjectReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(?object $_) => null, '?' . ReflectionUtil::OBJECT_TYPE_NAME));
    }

    private static function parentReflectionType(): DsHashableReflectionType
    {
        AssertEx::sameConstValues(TestCaseBase::class, parent::class);
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(TestCaseBase $_) => null, parent::class));
    }

    private static function nullableParentReflectionType(): DsHashableReflectionType
    {
        AssertEx::sameConstValues(TestCaseBase::class, parent::class);
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(?TestCaseBase $_) => null, '?' . parent::class));
    }

    private static function selfReflectionType(): DsHashableReflectionType
    {
        AssertEx::sameConstValues(ReflectionUtilTest::class, self::class);
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(ReflectionUtilTest $_) => null, self::class));
    }

    private static function nullableSelfReflectionType(): DsHashableReflectionType
    {
        AssertEx::sameConstValues(ReflectionUtilTest::class, self::class);
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(?ReflectionUtilTest $_) => null, '?' . self::class));
    }

    private static function stdClassReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(stdClass $_) => null, stdClass::class));
    }

    private static function nullableStdClassReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(?stdClass $_) => null, '?' . stdClass::class));
    }

    private static function stringReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::stringReflectionType());
    }

    private static function nullableStringReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::nullableStringReflectionType());
    }

    private static function traversableReflectionType(): DsHashableReflectionType
    {
        /**
         * @param Traversable<mixed> $_
         */
        $closureWithTypeParam = fn(Traversable $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, Traversable::class));
    }

    private static function nullableTraversableReflectionType(): DsHashableReflectionType
    {
        /**
         * @param ?Traversable<mixed> $_
         */
        $closureWithTypeParam = fn(?Traversable $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, '?' . Traversable::class));
    }

    /**
     * @template T
     *
     * @param T $value
     * @param list<T> &$to
     */
    private static function addToArrayAssertNew(mixed $value, array &$to): void
    {
        self::assertFalse(in_array($value, $to, /* strict: */ true));
        $to[] = $value;
    }

    /**
     * @template TKey
     * @template TValue
     *
     * @param DsMap<TKey, TValue> $from
     * @param DsMap<TKey, TValue> $to
     */
    private static function appendDsMapAssertNewKeys(DsMap $from, DsMap $to): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $dbgCtx->pushSubScope();
        foreach ($from as $key => $value) {
            $dbgCtx->resetTopSubScope(compact('key', 'value'));
            self::assertFalse($to->hasKey($key));
            $to->put($key, $value);
        }
        $dbgCtx->popSubScope();
    }

    /**
     * @return DsMap<DsHashableReflectionType, DsHashableReflectionType>
     */
    private static function builtinTypeToNullable(): DsMap
    {
        /** @var ?DsMap<DsHashableReflectionType, DsHashableReflectionType> $result */
        static $result = null;
        if ($result === null) {
            $result = new DsMap();
            DebugContext::getCurrentScope(/* out */ $dbgCtx);

            $result->put(self::arrayReflectionType(), self::nullableArrayReflectionType());
            $result->put(self::boolReflectionType(), self::nullableBoolReflectionType());
            $result->put(self::callableReflectionType(), self::nullableCallableReflectionType());
            $result->put(self::floatReflectionType(), self::nullableFloatReflectionType());
            $result->put(self::intReflectionType(), self::nullableIntReflectionType());
            $result->put(self::iterableReflectionType(), self::nullableIterableReflectionType());
            $result->put(self::stringReflectionType(), self::nullableStringReflectionType());

            $dbgCtx->pushSubScope();
            foreach ($result as $builtinType => $_) {
                $dbgCtx->resetTopSubScope(compact('builtinType'));
                self::assertInstanceOf(ReflectionNamedType::class, $builtinType->wrapped);
                self::assertTrue($builtinType->wrapped->isBuiltin());
            }
            $dbgCtx->popSubScope();
        }
        return $result;
    }

    /**
     * @return DsMap<DsHashableReflectionType, DsHashableReflectionType>
     */
    private static function classToNullable(): DsMap
    {
        /** @var ?DsMap<DsHashableReflectionType, DsHashableReflectionType> $result */
        static $result = null;
        if ($result === null) {
            $result = new DsMap();
            $result->put(self::closureReflectionType(), self::nullableClosureReflectionType());
            $result->put(self::dsMapReflectionType(), self::nullableDsMapReflectionType());
            $result->put(self::dummyBaseClassForTestsReflectionType(), self::nullableDummyBaseClassForTestsReflectionType());
            $result->put(self::dummyBaseInterfaceForTestsReflectionType(), self::nullableDummyBaseInterfaceForTests());
            $result->put(self::dummyDerivedInterfaceForTestsReflectionType(), self::nullableDummyDerivedInterfaceForTestsReflectionType());
            $result->put(self::dummyDerivedClassForTestsReflectionType(), self::nullableDummyDerivedClassForTestsReflectionType());
            $result->put(self::generatorReflectionType(), self::nullableGeneratorReflectionType());
            $result->put(self::iteratorReflectionType(), self::nullableIteratorReflectionType());
            $result->put(self::iteratorAggregateReflectionType(), self::nullableIteratorAggregateReflectionType());
            $result->put(self::objectReflectionType(), self::nullableObjectReflectionType());
            $result->put(self::parentReflectionType(), self::nullableParentReflectionType());
            $result->put(self::selfReflectionType(), self::nullableSelfReflectionType());
            $result->put(self::stdClassReflectionType(), self::nullableStdClassReflectionType());
            $result->put(self::traversableReflectionType(), self::nullableTraversableReflectionType());
        }
        return $result;
    }

    /** @noinspection PhpArrayTraversableCanBeReplacedWithIterableInspection */
    private static function unionArrayOrTraversableReflectionType(): DsHashableReflectionType
    {
        /**
         * @param Traversable<mixed>|array<mixed> $_
         */
        $closureWithTypeParam = fn(array|Traversable $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, Traversable::class . '|array'));
    }

    /** @noinspection PhpArrayTraversableCanBeReplacedWithIterableInspection */
    private static function unionNullArrayOrTraversableReflectionType(): DsHashableReflectionType
    {
        /**
         * @param Traversable<mixed>|null|array<mixed> $_
         */
        $closureWithTypeParam = fn(Traversable|array|null $_) => null;
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName($closureWithTypeParam, Traversable::class . '|array|null'));
    }

    private static function unionFloatOrIntReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(int|float $_) => null, 'int|float'));
    }

    private static function unionNullFloatOrIntReflectionType(): DsHashableReflectionType
    {
        return new DsHashableReflectionType(ReflectionUtil::extractReflectionTypeAssertName(fn(int|float|null $_) => null, 'int|float|null'));
    }

    /**
     * @return DsMap<DsHashableReflectionType, DsHashableReflectionType>
     */
    private static function unionToNullable(): DsMap
    {
        /** @var ?DsMap<DsHashableReflectionType, DsHashableReflectionType> $result */
        static $result = null;
        if ($result === null) {
            $result = new DsMap();
            $result->put(self::unionArrayOrTraversableReflectionType(), self::unionNullArrayOrTraversableReflectionType());
            $result->put(self::unionFloatOrIntReflectionType(), self::unionNullFloatOrIntReflectionType());
        }
        return $result;
    }

    /**
     * @return DsMap<DsHashableReflectionType, DsHashableReflectionType>
     */
    private static function typeToNullable(): DsMap
    {
        /** @var ?DsMap<DsHashableReflectionType, DsHashableReflectionType> $result */
        static $result = null;
        if ($result === null) {
            $result = new DsMap();
            self::appendDsMapAssertNewKeys(self::builtinTypeToNullable(), $result);
            self::appendDsMapAssertNewKeys(self::classToNullable(), $result);
            self::appendDsMapAssertNewKeys(self::unionToNullable(), $result);
        }
        return $result;
    }

    /**
     * @return list<DsHashableReflectionType>
     */
    private static function allTypes(): array
    {
        /** @var ?list<DsHashableReflectionType> $result */
        static $result = null;
        if ($result === null) {
            $result = [
                self::mixedReflectionType(),
            ];
            foreach (self::typeToNullable() as $type => $nullableType) {
                self::addToArrayAssertNew($type, /* ref */ $result);
                self::addToArrayAssertNew($nullableType, /* ref */ $result);
            }
        }
        return $result;
    }

    private static function assertCanBeAssignedTo(DsHashableReflectionType $source, DsHashableReflectionType $target, bool $expectedResult): void
    {
        $actualResult = ReflectionUtil::canBeAssignedTo($source->wrapped, $target->wrapped);
        self::assertSame($expectedResult, $actualResult);
    }

    public static function testCanBeAssignedToClassNullable(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $dbgCtx->pushSubScope();
        foreach (self::classToNullable() as $classType => $nullableClassType) {
            $dbgCtx->resetTopSubScope(compact('classType', 'nullableClassType'));

            // Any class type can be assigned to object
            self::assertCanBeAssignedTo($classType, self::objectReflectionType(), true);
            // but ?class cannot be assigned to object
            self::assertCanBeAssignedTo($nullableClassType, self::objectReflectionType(), false);

            // Any class type can be assigned to ?object
            self::assertCanBeAssignedTo($classType, self::nullableObjectReflectionType(), true);
            // Any ?class type can be assigned to ?object
            self::assertCanBeAssignedTo($nullableClassType, self::nullableObjectReflectionType(), true);
        }
        $dbgCtx->popSubScope();
    }

    public static function testCanBeAssignedToTypeNullable(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $dbgCtx->pushSubScope();
        foreach (self::typeToNullable() as $type => $nullableType) {
            $dbgCtx->resetTopSubScope(compact('type', 'nullableType'));
            $expectedNullableType = ReflectionUtil::getNullableReflectionTypeFor($type->wrapped);
            self::assertSame($expectedNullableType->__toString(), $nullableType->__toString());
            // Any type can be assigned to ?type
            self::assertCanBeAssignedTo($type, $nullableType, true);
            // but type? cannot be assigned to type
            self::assertCanBeAssignedTo($nullableType, $type, false);
        }
        $dbgCtx->popSubScope();
    }

    public static function testCanBeAssignedToAllTypes(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $dbgCtx->pushSubScope();
        foreach (self::allTypes() as $type) {
            $dbgCtx->resetTopSubScope(compact('type'));
            // Any type can be assigned to itself
            self::assertCanBeAssignedTo($type, $type, true);

            // Any type can be assigned to mixed
            self::assertCanBeAssignedTo($type, self::mixedReflectionType(), true);

            // mixed can be assigned only to mixed
            if (!$type->equals(self::mixedReflectionType())) {
                self::assertCanBeAssignedTo(self::mixedReflectionType(), $type, false);
            }
        }
        $dbgCtx->popSubScope();
    }

    public static function testCanBeAssignedToOneDirectionCases(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        /** @var DsMap<DsHashableReflectionType, DsHashableReflectionType> $cases */
        $cases = new DsMap();
        $cases->put(self::arrayReflectionType(), self::iterableReflectionType());
        $cases->put(self::closureReflectionType(), self::callableReflectionType());
        $cases->put(self::dsMapReflectionType(), self::iterableReflectionType());
        $cases->put(self::dsMapReflectionType(), self::traversableReflectionType());
        $cases->put(self::dsMapReflectionType(), self::iteratorAggregateReflectionType());
        $cases->put(self::dummyBaseClassForTestsReflectionType(), self::dummyBaseInterfaceForTestsReflectionType());
        $cases->put(self::dummyDerivedClassForTestsReflectionType(), self::dummyBaseClassForTestsReflectionType());
        $cases->put(self::dummyDerivedClassForTestsReflectionType(), self::dummyBaseInterfaceForTestsReflectionType());
        $cases->put(self::dummyDerivedClassForTestsReflectionType(), self::dummyDerivedInterfaceForTestsReflectionType());
        $cases->put(self::dummyDerivedInterfaceForTestsReflectionType(), self::dummyBaseInterfaceForTestsReflectionType());
        $cases->put(self::generatorReflectionType(), self::iterableReflectionType());
        $cases->put(self::generatorReflectionType(), self::iteratorReflectionType());
        $cases->put(self::generatorReflectionType(), self::traversableReflectionType());
        $cases->put(self::iteratorReflectionType(), self::iterableReflectionType());
        $cases->put(self::iteratorReflectionType(), self::traversableReflectionType());
        $cases->put(self::iteratorAggregateReflectionType(), self::iterableReflectionType());
        $cases->put(self::iteratorAggregateReflectionType(), self::traversableReflectionType());
        $cases->put(self::intReflectionType(), self::floatReflectionType());
        $cases->put(self::selfReflectionType(), self::parentReflectionType());
        $cases->put(self::traversableReflectionType(), self::iterableReflectionType());
        $cases->put(self::unionArrayOrTraversableReflectionType(), self::iterableReflectionType());
        $cases->put(self::intReflectionType(), self::unionFloatOrIntReflectionType());
        $dbgCtx->pushSubScope();
        foreach ($cases as $sourceType => $targetType) {
            $dbgCtx->resetTopSubScope(compact('sourceType', 'targetType'));

            self::assertCanBeAssignedTo($sourceType, $targetType, true);
            self::assertCanBeAssignedTo($targetType, $sourceType, false);
            $nullableSourceType = self::typeToNullable()->get($sourceType);
            $nullableTargetType = self::typeToNullable()->get($targetType);
            self::assertCanBeAssignedTo($nullableSourceType, $nullableTargetType, true);
            self::assertCanBeAssignedTo($nullableTargetType, $nullableSourceType, false);
        }
        $dbgCtx->popSubScope();
    }

    public static function testCanBeAssignedToBothDirectionsCases(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        /** @var DsMap<DsHashableReflectionType, DsHashableReflectionType> $cases */
        $cases = new DsMap();
        $cases->put(self::unionFloatOrIntReflectionType(), self::floatReflectionType());
        $cases->put(self::floatReflectionType(), self::unionFloatOrIntReflectionType());
        $dbgCtx->pushSubScope();
        foreach ($cases as $sourceType => $targetType) {
            $dbgCtx->resetTopSubScope(compact('sourceType', 'targetType'));

            self::assertCanBeAssignedTo($sourceType, $targetType, true);
            self::assertCanBeAssignedTo($targetType, $sourceType, true);
            $nullableSourceType = self::typeToNullable()->get($sourceType);
            $nullableTargetType = self::typeToNullable()->get($targetType);
            self::assertCanBeAssignedTo($nullableSourceType, $nullableTargetType, true);
            self::assertCanBeAssignedTo($nullableTargetType, $nullableSourceType, true);
        }
        $dbgCtx->popSubScope();
    }
}
