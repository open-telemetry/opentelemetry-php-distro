<?php

/** @noinspection PhpPluralMixedCanBeReplacedWithArrayInspection */

declare(strict_types=1);

namespace OTelDistroTests\Util;

use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\Log\Logger;
use Override;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type ConfigStore from \OTelDistroTests\Util\DebugContext as DebugContextConfigStore
 *
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class TestCaseBase extends TestCase
{
    /** @var DebugContextConfigStore */
    private array $debugContextConfigBeforeTest = [];

    protected function shouldDebugContextBeEnabledForThisTest(): bool
    {
        return true;
    }

    #[Override]
    public function setUp(): void
    {
        parent::setUp();

        $this->debugContextConfigBeforeTest = DebugContextConfig::getCopy();

        if (!$this->shouldDebugContextBeEnabledForThisTest()) {
            DebugContextConfig::enabled(false);
        }
    }

    #[Override]
    public function tearDown(): void
    {
        DebugContextConfig::set($this->debugContextConfigBeforeTest);

        parent::tearDown();
    }

    /**
     * @param array<string|int, mixed> $idToXyzMap
     *
     * @return string[]
     */
    public static function getIdsFromIdToMap(array $idToXyzMap): array
    {
        /** @var string[] $result */
        $result = [];
        foreach ($idToXyzMap as $id => $_) {
            $result[] = strval($id);
        }
        return $result;
    }

    /**
     * @param string       $namespace
     * @param class-string $fqClassName
     * @param string       $srcCodeFile
     *
     * @return Logger
     */
    public static function getLoggerStatic(string $namespace, string $fqClassName, string $srcCodeFile): Logger
    {
        return AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST, $namespace, $fqClassName, $srcCodeFile);
    }

    public static function dummyAssert(): bool
    {
        Assert::assertTrue(true); /** @phpstan-ignore staticMethod.alreadyNarrowedType */
        return true;
    }

    /**
     * @param iterable<array<array-key, mixed>> $srcDataProvider
     *
     * @return iterable<string, array<array-key, mixed>>
     */
    protected static function wrapDataProviderFromKeyValueMapToNamedDataSet(iterable $srcDataProvider): iterable
    {
        $dataSetIndex = 0;
        foreach ($srcDataProvider as $namedValuesMap) {
            $dataSetName = '#' . $dataSetIndex;
            $dataSetName .= ' ' . LoggableToString::convert($namedValuesMap);
            yield $dataSetName => array_values($namedValuesMap);
            ++$dataSetIndex;
        }
    }

    private const VERY_LONG_STRING_BASE_PREFIX = '<very long string prefix';
    private const VERY_LONG_STRING_BASE_SUFFIX = 'very long string suffix>';

    /**
     * @param positive-int $length
     */
    public static function generateVeryLongString(int $length): string
    {
        $midLength = $length - (strlen(self::VERY_LONG_STRING_BASE_PREFIX) + strlen(self::VERY_LONG_STRING_BASE_SUFFIX));
        Assert::assertGreaterThanOrEqual(0, $midLength);
        return self::VERY_LONG_STRING_BASE_PREFIX . str_repeat('-', $midLength) . self::VERY_LONG_STRING_BASE_SUFFIX;
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function dataProviderOneBoolArg(): iterable
    {
        foreach (BoolUtilForTests::ALL_VALUES as $value) {
            $dataSet = [$value];
            yield LoggableToString::convert($value) => $dataSet;
        }
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function dataProviderTwoBoolArgs(): iterable
    {
        foreach (BoolUtilForTests::ALL_VALUES as $value1) {
            foreach (BoolUtilForTests::ALL_VALUES as $value2) {
                $dataSet = [$value1, $value2];
                yield LoggableToString::convert($dataSet) => $dataSet;
            }
        }
    }

    public static function isSmoke(): bool
    {
        return AmbientContextForTests::testConfig()->isSmoke();
    }

    /**
     * @template T
     *
     * @param iterable<T> $variants
     *
     * @return iterable<T>
     */
    public static function adaptToSmoke(iterable $variants): iterable
    {
        if (!self::isSmoke()) {
            return $variants;
        }
        foreach ($variants as $key => $value) {
            if (ArrayUtilForTests::isOfArrayKeyType($key)) {
                return [$key => $value];
            } else {
                return [$value];
            }
        }
        return [];
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param iterable<TKey, TValue> $variants
     *
     * @return iterable<TKey, TValue>
     */
    public function adaptKeyValueToSmoke(iterable $variants): iterable
    {
        if (!self::isSmoke()) {
            return $variants;
        }
        foreach ($variants as $key => $value) {
            return [$key => $value];
        }
        return [];
    }

    /**
     * @return callable(iterable<mixed>): iterable<mixed>
     */
    public static function adaptToSmokeAsCallable(): callable
    {
        /**
         * @template T
         *
         * @param iterable<T> $dataSets
         *
         * @return iterable<T>
         */
        return function (iterable $dataSets): iterable {
            return self::adaptToSmoke($dataSets);
        };
    }

    /**
     * @param callable(): iterable<array<string, mixed>> $dataSetsGenerator
     *
     * @return iterable<string, array{MixedMap}>
     */
    public static function adaptDataSetsGeneratorToSmokeToDescToMixedMap(callable $dataSetsGenerator): iterable
    {
        return DataProviderForTestBuilder::convertEachDataSetToMixedMapAndAddDesc(fn() => self::adaptToSmoke($dataSetsGenerator()));
    }

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function adaptDataProviderForTestBuilderToSmokeToDescToMixedMap(DataProviderForTestBuilder $dataProviderForTestBuilder): iterable
    {
        return self::adaptDataSetsGeneratorToSmokeToDescToMixedMap(fn() => $dataProviderForTestBuilder->buildWithoutDataSetName()); // @phpstan-ignore argument.type
    }

    /**
     * @return iterable<array{bool}>
     */
    public static function dataProviderOneBoolArgAdaptedToSmoke(): iterable
    {
        return self::adaptToSmoke(self::dataProviderOneBoolArg());
    }
}
