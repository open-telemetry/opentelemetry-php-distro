<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests\ProdLogTests;

use OpenTelemetry\Distro\Log\LogBackend;
use OpenTelemetry\Distro\Log\LogFeature;
use OpenTelemetry\Distro\Log\LogLevel;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\DataProviderForTestBuilder;
use OTelDistroTests\Util\FileUtil;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\MixedMap;
use OTelDistroTests\Util\OsUtil;
use OTelDistroTests\Util\RangeUtil;
use OTelDistroTests\Util\TestCaseBase;

/**
 * @phpstan-import-type Context from LogBackend
 * @phpstan-import-type FormatAndWrite from LogBackend
 * @phpstan-import-type StringList from LogBackend
 */
class LoggingClassTraitTest extends TestCaseBase
{
    private const FEATURE_KEY = 'feature';
    private const MAX_ENABLED_LEVEL_KEY = 'max_enabled_level';
    private const STATEMENT_LEVEL_KEY = 'statement_level';
    private const FILE_KEY = 'file';
    private const FUNC_KEY = 'func';
    private const LINE_KEY = 'line';
    private const MESSAGE_KEY = 'message';
    private const CONTEXT_COUNT_KEY = 'context_count';

    private const SRC_ROOT_DIRS_KEY = 'src_root_dirs';
    private const EXPECTED_PROCESSED_FILE_KEY = 'expected_processed_file';

    /**
     * @return Context
     */
    private static function generateContext(int $count): array
    {
        /** @var ?Context $baseContext */
        static $baseContext = null;
        if ($baseContext === null) {
            $baseContext = [];
            $i = 0;
            $context['key_' . ($i++)] = null;
            $context['key_' . ($i++)] = 0;
            $context['key_' . ($i++)] = 234;
            $context['key_' . ($i++)] = 5.678;
            $context['key_' . ($i++)] = 'abc';
            $context['key_' . ($i++)] = '';
            $context['key_' . ($i++)] = true;
            /** @noinspection PhpUnusedLocalVariableInspection */
            $context['key_' . ($i++)] = false;
        }

        $result = array_slice($baseContext, offset: 0, length: min($count, count($baseContext)));
        foreach (RangeUtil::generateFromToIncluding(count($baseContext), $count) as $i) {
            $result['key_to_array_' . $i] = array_slice($baseContext, offset: 0, length: min($i, count($baseContext)));
        }
        return $result;
    }

    /**
     * @phpstan-param Context $expected
     * @phpstan-param Context $actual
     */
    private static function assertContextsEqual(array $expected, array $actual): void
    {
        AssertEx::equalRecursively($expected, $actual);
    }


    /**
     * @phpstan-param Context $expectedContext
     *
     * @return FormatAndWrite
     */
    private static function buildAssertCallback(
        LogLevel $expectedLevel,
        null|int|string $expectedFeatureOrCategory,
        string $expectedFile,
        int $expectedLine,
        string $expectedFunc,
        string $expectedMessage,
        array $expectedContext,
        LogLevel $maxEnabledLevel,
        bool &$assertEnabled,
        int &$countCallsWhenEnabled,
    ): callable {
        self::assertSame(0, $countCallsWhenEnabled);

        /**
         * @phpstan-param Context $actualContext
         */
        return function (
            LogLevel $actualLevel,
            null|int|string $actualFeature,
            string $actualFile,
            int $actualLine,
            string $actualFunc,
            string $actualMessage,
            array $actualContext
        ) use (
            $expectedLevel,
            $expectedFeatureOrCategory,
            $expectedFile,
            $expectedLine,
            $expectedFunc,
            $expectedMessage,
            $expectedContext,
            $maxEnabledLevel,
            &$assertEnabled,
            &$countCallsWhenEnabled,
        ): void {
            if (!$assertEnabled) {
                return;
            }

            ++$countCallsWhenEnabled;

            if ($expectedLevel->value > $maxEnabledLevel->value) {
                self::fail('Log statement should not be written');
            }

            self::assertSame($expectedLevel, $actualLevel);
            self::assertSame($expectedFeatureOrCategory, $actualFeature);
            self::assertSame($expectedFile, $actualFile);
            self::assertSame($expectedLine, $actualLine);
            self::assertSame($expectedFunc, $actualFunc);
            self::assertSame($expectedMessage, $actualMessage);
            self::assertContextsEqual($expectedContext, $actualContext);
        };
    }

    /**
     * @param list<LogLevel> $levels
     *
     * @return list<string>
     */
    private static function mapLevelsToNames(array $levels): array
    {
        return array_map(fn($logLevelEnum) => $logLevelEnum->name, $levels);
    }

    /**
     * @phpstan-param Context $context
     * @phpstan-param StringList $sourceCodeRootDirs
     */
    private static function invokeLogAndAssert(
        LogLevel $maxEnabledLevel,
        LogLevel $statementLevel,
        null|int|string $featureOrCategory,
        string $file,
        string $func,
        int $line,
        string $message,
        array $context,
        array $sourceCodeRootDirs = [],
        ?string $expectedProcessedFile = null,
    ): void {
        // $formatAndWrite should be no-op to create $tempLogBackend because LogBackend::__construct itself can issue log statements
        $assertEnabled = false;
        $countCallsWhenEnabled = 0;
        $fileToAssert = $expectedProcessedFile ?? $file;
        $formatAndWrite = self::buildAssertCallback(
            expectedLevel: $statementLevel,
            expectedFeatureOrCategory: $featureOrCategory,
            expectedFile: $fileToAssert,
            expectedLine:  $line,
            expectedFunc: $func,
            expectedMessage: $message,
            expectedContext: $context,
            maxEnabledLevel: $maxEnabledLevel,
            assertEnabled: /* ref */ $assertEnabled,
            countCallsWhenEnabled: /* ref */ $countCallsWhenEnabled,
        );
        $tempLogBackend = new LogBackend(maxEnabledLevel: $maxEnabledLevel->value, sourceCodeRootDirs: $sourceCodeRootDirs, formatAndWrite: $formatAndWrite);
        $assertEnabled = true;
        self::assertSame(0, $countCallsWhenEnabled);
        LogBackendTestUtil::saveActOnTempInstanceRestore(
            $tempLogBackend,
            fn() => TestLoggingClass::invokeLog($statementLevel, $featureOrCategory, $file, $func)?->with($line, $message, $context),
        );
        self::assertSame($statementLevel->value > $maxEnabledLevel->value ? 0 : 1, $countCallsWhenEnabled);
    }

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestEnabledLevel(): iterable
    {
        $allLogLevelNames = self::mapLevelsToNames(LogLevel::cases());
        return self::adaptDataProviderForTestBuilderToSmokeToDescToMixedMap(
            (new DataProviderForTestBuilder())
                ->addKeyedDimensionAllValuesCombinable(self::MAX_ENABLED_LEVEL_KEY, $allLogLevelNames)
                ->addKeyedDimensionAllValuesCombinable(self::STATEMENT_LEVEL_KEY, $allLogLevelNames)
        );
    }

    /**
     * @dataProvider dataProviderForTestEnabledLevel
     */
    public function testEnabledLevel(MixedMap $testArgs): void
    {
        self::invokeLogAndAssert(
            maxEnabledLevel: AssertEx::notNull(LogLevel::tryToFindByName($testArgs->getString(self::MAX_ENABLED_LEVEL_KEY))),
            statementLevel: AssertEx::notNull(LogLevel::tryToFindByName($testArgs->getString(self::STATEMENT_LEVEL_KEY))),
            featureOrCategory: LogCategoryForTests::TEST,
            file: __FILE__,
            func: __FUNCTION__,
            line: __LINE__,
            message: 'Dummy message',
            context: ['dummy context key' => 'dummy context value'],
        );
    }

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestVariousStatementPropertiesValues(): iterable
    {
        return self::adaptDataProviderForTestBuilderToSmokeToDescToMixedMap(
            (new DataProviderForTestBuilder())
                ->addKeyedDimensionOnlyFirstValueCombinable(self::MAX_ENABLED_LEVEL_KEY, self::mapLevelsToNames([LogLevel::info, LogLevel::trace]))
                ->addKeyedDimensionOnlyFirstValueCombinable(self::STATEMENT_LEVEL_KEY, self::mapLevelsToNames([LogLevel::trace, LogLevel::debug]))
                ->addKeyedDimensionOnlyFirstValueCombinable(self::FEATURE_KEY, [LogFeature::BOOTSTRAP, LogFeature::CONFIG, null, LogCategoryForTests::CONFIG, LogCategoryForTests::TEST])
                ->addKeyedDimensionOnlyFirstValueCombinable(self::FILE_KEY, [__FILE__, 'dummy/file/path'])
                ->addKeyedDimensionOnlyFirstValueCombinable(self::FUNC_KEY, ['dummy_func', 'dummy_func'])
                ->addKeyedDimensionOnlyFirstValueCombinable(self::LINE_KEY, [123, 987654])
                ->addKeyedDimensionOnlyFirstValueCombinable(self::MESSAGE_KEY, ['Dummy message', ''])
                ->addKeyedDimensionOnlyFirstValueCombinable(self::CONTEXT_COUNT_KEY, [1, 2, 3, 10, 0])
        );
    }

    /**
     * @dataProvider dataProviderForTestVariousStatementPropertiesValues
     */
    public function testVariousStatementPropertiesValues(MixedMap $testArgs): void
    {
        $feature = $testArgs->get(self::FEATURE_KEY);
        /** @var null|int|string $feature */

        self::invokeLogAndAssert(
            maxEnabledLevel: AssertEx::notNull(LogLevel::tryToFindByName($testArgs->getString(self::MAX_ENABLED_LEVEL_KEY))),
            statementLevel: AssertEx::notNull(LogLevel::tryToFindByName($testArgs->getString(self::STATEMENT_LEVEL_KEY))),
            featureOrCategory: $feature,
            file: $testArgs->getString(self::FILE_KEY),
            func: $testArgs->getString(self::FUNC_KEY),
            line: $testArgs->getInt(self::LINE_KEY),
            message: $testArgs->getString(self::MESSAGE_KEY),
            context: self::generateContext($testArgs->getInt(self::CONTEXT_COUNT_KEY)),
        );
    }

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestSourceCodeFilePathProcessing(): iterable
    {
        /**
         * @phpstan-param StringList $srcRootDirs
         *
         * @return array<string, mixed>
         */
        $generateDataSet = function (string $file, array $srcRootDirs, string $expectedProcessedFile): array {
            /** @var StringList $srcRootDirs */
            return [
                self::FILE_KEY => FileUtil::adaptUnixDirectorySeparators($file),
                self::SRC_ROOT_DIRS_KEY => array_map(FileUtil::adaptUnixDirectorySeparators(...), $srcRootDirs),
                self::EXPECTED_PROCESSED_FILE_KEY => FileUtil::adaptUnixDirectorySeparators($expectedProcessedFile),
            ];
        };

        /**
         * @return iterable<array<string, mixed>>
         */
        $generateDataSets = function () use ($generateDataSet): iterable {
            yield $generateDataSet('/prod/php/MyClass.php', ['/prod/php'], 'MyClass.php');
            if (OsUtil::isWindows()) {
                yield $generateDataSet("C:\\prod\\php\\MyClass.php", ["C:\\prod\\php"], 'MyClass.php');
            }
            yield $generateDataSet('/prod/MyClass.php', ['/prod'], 'MyClass.php');
            yield $generateDataSet('/MyClass.php', ['/'], 'MyClass.php');
            yield $generateDataSet('/MyClass.php', [''], '/MyClass.php');
            yield $generateDataSet('/MyClass.php', ['/'], 'MyClass.php');
            yield $generateDataSet('/vendor/SomeVendorClass.php', ['/prod'], '/vendor/SomeVendorClass.php');

            yield $generateDataSet('/prod/php/MyProdClass.php', ['/prod/php', '/tools/build'], 'MyProdClass.php');
            yield $generateDataSet('/prod/php/Util/TestUtil.php', ['/prod/php', '/tools/build'], 'TestUtil.php');
            yield $generateDataSet('/tools/build/ComposerUtil.php', ['/prod/php', '/tools/build'], 'ComposerUtil.php');
            yield $generateDataSet('/prod/OutSideRootDirsA.php', ['/prod/php', '/tools/build'], '/prod/OutSideRootDirsA.php');
            yield $generateDataSet('/tools/OutSideRootDirsB.php', ['/prod/php', '/tools/build'], '/tools/OutSideRootDirsB.php');
            yield $generateDataSet('/OutSideRootDirsC.php', ['/prod/php', '/tools/build'], '/OutSideRootDirsC.php');
        };

        return self::adaptDataSetsGeneratorToSmokeToDescToMixedMap($generateDataSets);
    }

    /**
     * @dataProvider dataProviderForTestSourceCodeFilePathProcessing
     */
    public function testSourceCodeFilePathProcessing(MixedMap $testArgs): void
    {
        /** @var StringList $sourceCodeRootDirs */
        $sourceCodeRootDirs = $testArgs->get(self::SRC_ROOT_DIRS_KEY);
        self::invokeLogAndAssert(
            maxEnabledLevel: LogLevel::trace,
            statementLevel: LogLevel::trace,
            featureOrCategory: LogCategoryForTests::TEST,
            file: $testArgs->getString(self::FILE_KEY),
            func: __FUNCTION__,
            line: __LINE__,
            message: 'Dummy message',
            context: ['dummy context key' => 'dummy context value'],
            sourceCodeRootDirs: $sourceCodeRootDirs,
            expectedProcessedFile: $testArgs->getString(self::EXPECTED_PROCESSED_FILE_KEY),
        );
    }

    /**
     * @phpstan-param Context $context
     */
    private static function noopFormatAndWrite(LogLevel $level, null|int|string $featureOrCategory, string $file, int $line, string $func, string $message, array $context): void
    {
    }

    public function testWithIsNotEvaluatedIfLevelDisabled(): void
    {
        $detectShouldFail = false;
        $detectCount = 0;
        $detect = function () use (&$detectCount, &$detectShouldFail): int {
            ++$detectCount;
            if ($detectShouldFail) { // @phpstan-ignore if.alwaysFalse
                self::fail('$detectShouldFail is true');
            }
            return $detectCount;
        };

        $statementLevels = array_values(array_filter(LogLevel::cases(), fn($logLevel) => $logLevel !== LogLevel::off));
        foreach ($statementLevels as $statementLevel) {
            foreach ([true, false] as $isLevelEnabled) {
                $maxEnabledLevel = $isLevelEnabled ? $statementLevel : LogLevel::from($statementLevel->value - 1);
                $detectCount = 0;
                $detectShouldFail = false;
                $tempLogBackend = new LogBackend(maxEnabledLevel: $maxEnabledLevel->value, sourceCodeRootDirs: [], formatAndWrite: self::noopFormatAndWrite(...));
                LogBackendTestUtil::saveActOnTempInstanceRestore(
                    $tempLogBackend,
                    function () use ($statementLevel, $detect, $isLevelEnabled, &$detectShouldFail): void {
                        self::assertFalse($detectShouldFail);
                        $detect();
                        if (!$isLevelEnabled) {
                            $detectShouldFail = true;
                        }
                        TestLoggingClass::invokeLog(level: $statementLevel, featureOrCategory: null, file: __FILE__, func: __FUNCTION__)
                            ?->with(__LINE__, '$detect(): ' . $detect(), ['$detect()' => $detect()]);
                    },
                );

                if ($isLevelEnabled) {
                    self::assertFalse($detectShouldFail);
                    self::assertSame(3, $detectCount); // @phpstan-ignore staticMethod.impossibleType
                } else {
                    self::assertTrue($detectShouldFail);
                    self::assertSame(1, $detectCount); // @phpstan-ignore staticMethod.impossibleType
                }
            }
        }
    }
}
