<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests\ConfigTests;

use OTelDistroTests\Util\Config\EnumOptionParser;
use OTelDistroTests\Util\ReflectionUtil;
use OTelDistroTests\Util\TestCaseBase;

class EnumOptionsParsingTest extends TestCaseBase
{
    /**
     * @return list<array{EnumOptionParser<mixed>, list<OptionTestValidValue<mixed>>}>
     */
    public static function dataProviderForTestEnumWithSomeEntriesArePrefixOfOtherOnes(): array
    {
        /** @noinspection PhpParamsInspection, PhpUnnecessaryLocalVariableInspection */
        $testArgsTuples = [
            [
                EnumOptionParser::useEnumCasesNames(
                    enumClass: EnumOptionsParsingTestDummyEnum::class,
                    parsedValueReflType: ReflectionUtil::extractReflectionTypeFromClosureParamAssertName(fn(EnumOptionsParsingTestDummyEnum $_) => null, EnumOptionsParsingTestDummyEnum::class),
                    isCaseSensitive: true,
                    isUnambiguousPrefixAllowed: true,
                ),
                [
                    new OptionTestValidValue(" anotherEnumEntry\t\n", EnumOptionsParsingTestDummyEnum::anotherEnumEntry),
                    new OptionTestValidValue("anotherEnumEnt  \n ", EnumOptionsParsingTestDummyEnum::anotherEnumEntry),
                    new OptionTestValidValue("another  \n ", EnumOptionsParsingTestDummyEnum::anotherEnumEntry),
                    new OptionTestValidValue('a', EnumOptionsParsingTestDummyEnum::anotherEnumEntry),
                    new OptionTestValidValue(' enumEntry', EnumOptionsParsingTestDummyEnum::enumEntry),
                    new OptionTestValidValue("\t  enumEntryWithSuffix\n ", EnumOptionsParsingTestDummyEnum::enumEntryWithSuffix),
                    new OptionTestValidValue('enumEntryWithSuffix2', EnumOptionsParsingTestDummyEnum::enumEntryWithSuffix2),
                ]
            ],
            [
                EnumOptionParser::useEnumCasesValues(
                    enumClass: EnumOptionsParsingTestDummyBackedEnum::class,
                    parsedValueReflType:
                    ReflectionUtil::extractReflectionTypeFromClosureParamAssertName(fn(EnumOptionsParsingTestDummyBackedEnum $_) => null, EnumOptionsParsingTestDummyBackedEnum::class),
                    isCaseSensitive: true,
                    isUnambiguousPrefixAllowed: true,
                ),
                [
                    new OptionTestValidValue(" anotherEnumEntry_value\t\n", EnumOptionsParsingTestDummyBackedEnum::anotherEnumEntry),
                    new OptionTestValidValue("anotherEnumEnt  \n ", EnumOptionsParsingTestDummyBackedEnum::anotherEnumEntry),
                    new OptionTestValidValue("another  \n ", EnumOptionsParsingTestDummyBackedEnum::anotherEnumEntry),
                    new OptionTestValidValue('a', EnumOptionsParsingTestDummyBackedEnum::anotherEnumEntry),
                    new OptionTestValidValue(' enumEntry_value', EnumOptionsParsingTestDummyBackedEnum::enumEntry),
                    new OptionTestValidValue("\t  enumEntryWithSuffix_value\n ", EnumOptionsParsingTestDummyBackedEnum::enumEntryWithSuffix),
                    new OptionTestValidValue('enumEntryWithSuffix2_value', EnumOptionsParsingTestDummyBackedEnum::enumEntryWithSuffix2),
                ]
            ],
            [
                new EnumOptionParser(
                    dbgDesc: '<enum defined in ' . __METHOD__ . '>',
                    parsedValueReflType: ReflectionUtil::stringReflectionType(),
                    nameValuePairs: [
                        ['enumEntry', 'enumEntry_value'],
                        ['enumEntryWithSuffix', 'enumEntryWithSuffix_value'],
                        ['enumEntryWithSuffix2', 'enumEntryWithSuffix2_value'],
                        ['anotherEnumEntry', 'anotherEnumEntry_value'],
                    ],
                    isCaseSensitive:            true,
                    isUnambiguousPrefixAllowed: true
                ),
                [
                    new OptionTestValidValue(" anotherEnumEntry\t\n", 'anotherEnumEntry_value'),
                    new OptionTestValidValue("anotherEnumEnt  \n ", 'anotherEnumEntry_value'),
                    new OptionTestValidValue("another  \n ", 'anotherEnumEntry_value'),
                    new OptionTestValidValue('a', 'anotherEnumEntry_value'),
                    new OptionTestValidValue(' enumEntry', 'enumEntry_value'),
                    new OptionTestValidValue("\t  enumEntryWithSuffix\n ", 'enumEntryWithSuffix_value'),
                    new OptionTestValidValue('enumEntryWithSuffix2', 'enumEntryWithSuffix2_value'),
                ],
            ],
        ];

        return $testArgsTuples; // @phpstan-ignore return.type
    }

    /**
     * @template T
     *
     * @dataProvider dataProviderForTestEnumWithSomeEntriesArePrefixOfOtherOnes
     *
     * @param EnumOptionParser<T>           $optionParser
     * @param list<OptionTestValidValue<T>> $additionalValidValues
     */
    public function testEnumWithSomeEntriesArePrefixOfOtherOnes(EnumOptionParser $optionParser, array $additionalValidValues): void
    {
        /** @noinspection SpellCheckingInspection */
        /** @var list<string> $additionalInvalidRawValues */
        static $additionalInvalidRawValues = [
            'e',
            'enum',
            'enumEnt',
            'enumEntr',
            'enumEntryWithSuffi',
            'enumEntryWithSuffix2_',
            'ENUMENTRY',
            'enumEntryWithSUFFIX',
            'ENUMEntryWithSuffix2',
            'anotherenumentry',
            'Another',
            'A',
        ];

        $testValuesGenerator = new EnumOptionTestValuesGenerator($optionParser, $additionalValidValues, $additionalInvalidRawValues);

        VariousOptionsParsingTest::parseValidValueTestImpl($testValuesGenerator, $optionParser);
        VariousOptionsParsingTest::parseInvalidValueTestImpl($testValuesGenerator, $optionParser);
    }
}
