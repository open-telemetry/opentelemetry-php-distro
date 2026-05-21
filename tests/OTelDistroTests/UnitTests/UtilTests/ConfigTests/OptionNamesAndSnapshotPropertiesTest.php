<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests\ConfigTests;

use OpenTelemetry\Distro\Util\TextUtil;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\ConfigSnapshotForProd;
use OTelDistroTests\Util\Config\ConfigSnapshotForTests;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\Config\OptionForTestsName;
use OTelDistroTests\Util\Config\OptionMetadata as OptionMetadata;
use OTelDistroTests\Util\Config\OptionsForProdMetadata;
use OTelDistroTests\Util\Config\OptionsForTestsMetadata;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\TestCaseBase;
use OTelDistroTests\Util\TextUtilForTests;
use UnitEnum;

class OptionNamesAndSnapshotPropertiesTest extends TestCaseBase
{
    public function testOptionNamesAndMetadataMapMatch(): void
    {
        /**
         * @param UnitEnum[] $optNameCases
         * @param array<string, OptionMetadata<mixed>> $optMetas
         */
        $impl = function (array $optNameCases, array $optMetas): void {
            DebugContext::getCurrentScope(/* out */ $dbgCtx);
            $optNamesFromCases = array_map(fn($optNameCase) => $optNameCase->name, $optNameCases); // @phpstan-ignore property.nonObject
            sort(/* ref */ $optNamesFromCases);
            $optNamesFromMetas = array_keys($optMetas);
            sort(/* ref */ $optNamesFromMetas);
            $dbgCtx->add(compact('optNamesFromCases', 'optNamesFromMetas'));
            AssertEx::arraysHaveTheSameContent($optNamesFromCases, $optNamesFromMetas);
        };

        $impl(OptionForProdName::cases(), OptionsForProdMetadata::get());
        $impl(OptionForTestsName::cases(), OptionsForTestsMetadata::get());
    }

    /**
     * @return iterable<array{UnitEnum[], string[]}>
     */
    public static function dataProviderForTestOptionNamesAndSnapshotPropertiesMatch(): iterable
    {
        return [
            [OptionForProdName::cases(), ConfigSnapshotForProd::propertyNamesForOptions()],
            [OptionForTestsName::cases(), ConfigSnapshotForTests::propertyNamesForOptions()],
        ];
    }

    /**
     * @dataProvider dataProviderForTestOptionNamesAndSnapshotPropertiesMatch
     *
     * @param UnitEnum[] $optNameCases
     * @param string[] $propertyNamesForOptions
     */
    public function testOptionNamesAndSnapshotPropertiesMatch(array $optNameCases, array $propertyNamesForOptions): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $remainingSnapPropNames = $propertyNamesForOptions;
        foreach ($optNameCases as $optNameCase) {
            $dbgCtx->add(compact('optNameCase', 'remainingSnapPropNames'));
            self::assertTrue(ArrayUtilForTests::removeFirstByValue(/* in,out */ $remainingSnapPropNames, TextUtilForTests::snakeToCamelCase($optNameCase->name)));
        }

        self::assertEmpty($remainingSnapPropNames);
    }

    public function testOptionNameToEnvVarName(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        /** @var class-string<OptionForProdName|OptionForTestsName> $optNameEnumClass */
        foreach ([OptionForProdName::class, OptionForTestsName::class] as $optNameEnumClass) {
            $dbgCtx->add(compact('optNameEnumClass'));
            foreach ($optNameEnumClass::cases() as $optName) {
                $dbgCtx->add(compact('optName'));
                $envVarName = $optName->toEnvVarName();
                $dbgCtx->add(compact('envVarName'));
                self::assertTrue(TextUtil::isSuffixOf(strtoupper($optName->name), $envVarName));
            }
        }
    }

    public function testProdOptionNameToEnvVar(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        foreach (OptionForProdName::cases() as $optName) {
            $dbgCtx->add(compact('optName'));
            $envVarNamePrefix = $optName->getEnvVarNamePrefix();
            $dbgCtx->add(compact('envVarNamePrefix'));
            $envVarName = $optName->toEnvVarName();
            $dbgCtx->add(compact('envVarName'));
            self::assertStringStartsWith($envVarNamePrefix, $envVarName);
        }
    }
}
