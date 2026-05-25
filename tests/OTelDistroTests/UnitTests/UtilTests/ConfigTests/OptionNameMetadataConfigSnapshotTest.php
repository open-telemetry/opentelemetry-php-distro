<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests\ConfigTests;

use OpenTelemetry\Distro\Log\LogLevel;
use OpenTelemetry\Distro\Util\TextUtil;
use OpenTelemetry\Distro\Util\WildcardListMatcher;
use OTelDistroTests\ComponentTests\Util\AppCodeHostKind;
use OTelDistroTests\ComponentTests\Util\TestGroupName;
use OTelDistroTests\ComponentTests\Util\TestMatrixRow;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\BoolOptionMetadata;
use OTelDistroTests\Util\Config\BoolOptionParser;
use OTelDistroTests\Util\Config\ConfigSnapshotForProd;
use OTelDistroTests\Util\Config\ConfigSnapshotForTests;
use OTelDistroTests\Util\Config\DurationOptionParser;
use OTelDistroTests\Util\Config\FloatOptionMetadata;
use OTelDistroTests\Util\Config\IntOptionMetadata;
use OTelDistroTests\Util\Config\LogLevelOptionMetadata;
use OTelDistroTests\Util\Config\NullableAppCodeHostKindOptionMetadata;
use OTelDistroTests\Util\Config\NullableBoolOptionMetadata;
use OTelDistroTests\Util\Config\NullableLogLevelOptionMetadata;
use OTelDistroTests\Util\Config\NullableStringOptionMetadata;
use OTelDistroTests\Util\Config\NullableTestGroupNameOptionMetadata;
use OTelDistroTests\Util\Config\NullableWildcardListOptionMetadata;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\Config\OptionForTestsName;
use OTelDistroTests\Util\Config\OptionMetadata;
use OTelDistroTests\Util\Config\OptionParser;
use OTelDistroTests\Util\Config\OptionsForProdMetadata;
use OTelDistroTests\Util\Config\OptionsForTestsMetadata;
use OTelDistroTests\Util\Config\WildcardListOptionParser;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\Duration;
use OTelDistroTests\Util\DurationUnit;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\ReflectionUtil;
use OTelDistroTests\Util\TestCaseBase;
use OTelDistroTests\Util\TextUtilForTests;
use ReflectionType;

/**
 * @phpstan-type ConfigKind 'prod'|'tests'
 * @phpstan-type ConfigSnapshot ConfigSnapshotForProd|ConfigSnapshotForTests
 */
class OptionNameMetadataConfigSnapshotTest extends TestCaseBase
{
    private const PROD_CONFIG_KIND = 'prod';
    private const TESTS_CONFIG_KIND = 'tests';
    private const ALL_CONFIG_KINDS = [self::PROD_CONFIG_KIND, self::TESTS_CONFIG_KIND];

    public function test0OptionParserGetParseReturnType(): void
    {
        $impl = function (OptionParser $optParser, ReflectionType $expected): void {
            $actual = $optParser->getParsedValueReflectionType();
            self::assertSame($expected->__toString(), $actual->__toString());
        };

        $impl(new BoolOptionParser(), ReflectionUtil::boolReflectionType());
        $impl(new DurationOptionParser(null, null, DurationUnit::s), ReflectionUtil::extractReflectionTypeFromClosureParamAssertName(fn(Duration $_) => null, Duration::class));
        $impl(new WildcardListOptionParser(), ReflectionUtil::extractReflectionTypeFromClosureParamAssertName(fn(WildcardListMatcher $_) => null, WildcardListMatcher::class));
    }

    public function test1OptionMetadataGetParseReturnType(): void
    {
        $impl = function (OptionMetadata $optMeta, ReflectionType $expected): void {
            $actual = $optMeta->getParsedValueReflectionType();
            self::assertSame($expected->__toString(), $actual->__toString());
        };

        $impl(new BoolOptionMetadata(true), ReflectionUtil::boolReflectionType());
        $impl(new IntOptionMetadata(null, null, 123), ReflectionUtil::intReflectionType());
        $impl(new FloatOptionMetadata(null, null, 9876.5), ReflectionUtil::floatReflectionType());
        $impl(new LogLevelOptionMetadata(LogLevel::warning), ReflectionUtil::extractReflectionTypeFromClosureParamAssertName(fn(LogLevel $_) => null, LogLevel::class));

        $impl(new NullableAppCodeHostKindOptionMetadata(), ReflectionUtil::extractReflectionTypeFromClosureParamAssertName(fn(?AppCodeHostKind $_) => null, '?' . AppCodeHostKind::class));
        $impl(new NullableBoolOptionMetadata(), ReflectionUtil::nullableBoolReflectionType());
        $impl(new NullableLogLevelOptionMetadata(), ReflectionUtil::extractReflectionTypeFromClosureParamAssertName(fn(?LogLevel $_) => null, '?' . LogLevel::class));
        $impl(new NullableStringOptionMetadata(), ReflectionUtil::nullableStringReflectionType());
        $impl(new NullableTestGroupNameOptionMetadata(), ReflectionUtil::extractReflectionTypeFromClosureParamAssertName(fn(?TestGroupName $_) => null, '?' . TestGroupName::class));
        $impl(new NullableWildcardListOptionMetadata(), ReflectionUtil::extractReflectionTypeFromClosureParamAssertName(fn(?WildcardListMatcher $_) => null, '?' . WildcardListMatcher::class));

        $impl(
            OptionsForTestsMetadata::get()[OptionForTestsName::matrix_row->name],
            ReflectionUtil::extractReflectionTypeFromClosureParamAssertName(fn(?TestMatrixRow $_) => null, '?' . TestMatrixRow::class)
        );
    }

    /**
     * @param ConfigKind $configKind
     */
    private static function assertValidConfigKind(string $configKind): void
    {
        if (in_array($configKind, self::ALL_CONFIG_KINDS, /* strict: */ true)) {
            return;
        }

        self::fail(LoggableToString::convertMessageAndContext('Unknown config kind', compact('configKind')));
    }

    /**
     * @return iterable<array{ConfigKind}>
     */
    public static function dataProviderGeneratingConfigKind(): iterable
    {
        foreach (self::ALL_CONFIG_KINDS as $configKind) {
            yield [$configKind];
        }
    }

    /**
     * @param ConfigKind $configKind
     *
     * @return class-string<OptionForProdName>|class-string<OptionForTestsName>
     */
    private static function getNameEnumClass(string $configKind): string
    {
        self::assertValidConfigKind($configKind);

        return match ($configKind) {
            self::PROD_CONFIG_KIND => OptionForProdName::class,
            self::TESTS_CONFIG_KIND => OptionForTestsName::class,
        };
    }

    /**
     * @param ConfigKind $configKind
     *
     * @return array<string, OptionMetadata<mixed>>
     */
    private static function getOptionsMetadata(string $configKind): array
    {
        self::assertValidConfigKind($configKind);

        return match ($configKind) {
            self::PROD_CONFIG_KIND => OptionsForProdMetadata::get(),
            self::TESTS_CONFIG_KIND => OptionsForTestsMetadata::get(),
        };
    }

    /**
     * @param ConfigKind $configKind
     *
     * @return list<string>
     */
    private static function getSnapshotPropertiesNamesForOptions(string $configKind): array
    {
        self::assertValidConfigKind($configKind);

        return match ($configKind) {
            self::PROD_CONFIG_KIND => ConfigSnapshotForProd::propertyNamesForOptions(),
            self::TESTS_CONFIG_KIND => ConfigSnapshotForTests::propertyNamesForOptions(),
        };
    }

    /**
     * @param ConfigKind $configKind
     * @param array<string, mixed> $optNameToParsedValue
     *
     * @return ConfigSnapshot
     */
    private static function newSnapshot(string $configKind, array $optNameToParsedValue): ConfigSnapshotForProd|ConfigSnapshotForTests
    {
        self::assertValidConfigKind($configKind);

        return match ($configKind) {
            self::PROD_CONFIG_KIND => new ConfigSnapshotForProd($optNameToParsedValue),
            self::TESTS_CONFIG_KIND => new ConfigSnapshotForTests($optNameToParsedValue),
        };
    }

    /**
     * @param ConfigKind $configKind
     *
     * @return class-string<ConfigSnapshotForProd>|class-string<ConfigSnapshotForTests>
     */
    private static function getSnapshotClass(string $configKind): string
    {
        self::assertValidConfigKind($configKind);

        return match ($configKind) {
            self::PROD_CONFIG_KIND => ConfigSnapshotForProd::class,
            self::TESTS_CONFIG_KIND => ConfigSnapshotForTests::class,
        };
    }

    /**
     * @dataProvider dataProviderGeneratingConfigKind
     *
     * @param ConfigKind $configKind
     */
    public function testOptionNamesAndMetadataMapMatch(string $configKind): void
    {
        $optNameCases = self::getNameEnumClass($configKind)::cases();
        $optMetas = self::getOptionsMetadata($configKind);

        $optNamesFromCases = array_map(fn($optNameCase) => $optNameCase->name, $optNameCases);
        sort(/* ref */ $optNamesFromCases);
        $optNamesFromMetas = array_keys($optMetas);
        sort(/* ref */ $optNamesFromMetas);
        AssertEx::arraysHaveTheSameContent($optNamesFromCases, $optNamesFromMetas);
    }

    /**
     * @dataProvider dataProviderGeneratingConfigKind
     *
     * @param ConfigKind $configKind
     */
    public function testOptionNamesAndSnapshotPropertiesMatch(string $configKind): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $optNameCases = self::getNameEnumClass($configKind)::cases();
        $propertyNamesForOptions = self::getSnapshotPropertiesNamesForOptions($configKind);

        $remainingSnapPropNames = $propertyNamesForOptions;
        $dbgCtx->pushSubScope();
        foreach ($optNameCases as $optNameCase) {
            $dbgCtx->resetTopSubScope(compact('optNameCase', 'remainingSnapPropNames'));
            self::assertTrue(ArrayUtilForTests::removeFirstByValue(/* in,out */ $remainingSnapPropNames, TextUtilForTests::snakeToCamelCase($optNameCase->name)));
        }
        $dbgCtx->popSubScope();

        self::assertEmpty($remainingSnapPropNames);
    }

    /**
     * @dataProvider dataProviderGeneratingConfigKind
     *
     * @param ConfigKind $configKind
     */
    public function testSnapshotCanBeAssignedDefaults(string $configKind): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $optNameEnumClass = self::getNameEnumClass($configKind);
        $optNameCases = $optNameEnumClass::cases();
        $optNameToMetadata = self::getOptionsMetadata($configKind);
        $optNameToDefaultValue = [];
        $dbgCtx->pushSubScope();
        foreach ($optNameToMetadata as $optName => $optMeta) {
            $dbgCtx->resetTopSubScope(compact('optName', 'optMeta'));
            ArrayUtilForTests::addAssertingKeyNew($optName, $optMeta->defaultValue(), /* ref */ $optNameToDefaultValue);
        }
        $dbgCtx->popSubScope();

        $configSnapshot = self::newSnapshot($configKind, $optNameToDefaultValue);

        $dbgCtx->pushSubScope();
        foreach ($optNameCases as $optNameCase) {
            $dbgCtx->resetTopSubScope(compact('optNameCase'));
            self::assertSame($optNameToDefaultValue[$optNameCase->name], $configSnapshot->getOptionValueByName($optNameCase));
        }
        $dbgCtx->popSubScope();
    }

    /**
     * @dataProvider dataProviderGeneratingConfigKind
     *
     * @param ConfigKind $configKind
     */
    public function testParsedValueCanBeAssgnedToSnapshotProperty(string $configKind): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $optNameToMetadata = self::getOptionsMetadata($configKind);
        $snapshotClass = self::getSnapshotClass($configKind);

        $dbgCtx->pushSubScope();
        foreach ($optNameToMetadata as $optName => $optMeta) {
            $dbgCtx->resetTopSubScope(compact('optName', 'optMeta'));
            $optMetadataParsedValueReflType = $optMeta->getParsedValueReflectionType();
            $dbgCtx->add(compact('optMetadataParsedValueReflType'));
            $snapshotPropertyReflType = $snapshotClass::getPropertyReflectionType(self::getNameEnumClass($configKind)::findByName($optName));
            $dbgCtx->add(compact('snapshotPropertyReflType'));
            self::assertTrue(ReflectionUtil::canBeAssignedTo(source: $optMetadataParsedValueReflType, target: $snapshotPropertyReflType));
        }
        $dbgCtx->popSubScope();
    }

    /**
     * @dataProvider dataProviderGeneratingConfigKind
     *
     * @param ConfigKind $configKind
     */
    public function testOptionNameToEnvVarName(string $configKind): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $optNameEnumCases = self::getNameEnumClass($configKind)::cases();
        $dbgCtx->pushSubScope();
        foreach ($optNameEnumCases as $optName) {
            $dbgCtx->resetTopSubScope(compact('optName'));
            $envVarName = $optName->toEnvVarName();
            $dbgCtx->add(compact('envVarName'));
            self::assertTrue(TextUtil::isSuffixOf(strtoupper($optName->name), $envVarName));
        }
        $dbgCtx->popSubScope();
    }

    public function testProdOptionNameToEnvVar(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $dbgCtx->pushSubScope();
        foreach (OptionForProdName::cases() as $optName) {
            $dbgCtx->resetTopSubScope(compact('optName'));
            $envVarNamePrefix = $optName->getEnvVarNamePrefix();
            $envVarName = $optName->toEnvVarName();
            self::assertStringStartsWith($envVarNamePrefix, $envVarName);
        }
        $dbgCtx->popSubScope();
    }
}
