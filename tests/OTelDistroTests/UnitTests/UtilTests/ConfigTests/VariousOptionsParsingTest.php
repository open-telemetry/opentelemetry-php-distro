<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests\ConfigTests;

use OpenTelemetry\Distro\Util\TextUtil;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\BoolOptionParser;
use OTelDistroTests\Util\Config\CustomOptionParser;
use OTelDistroTests\Util\Config\DurationOptionMetadata;
use OTelDistroTests\Util\Config\DurationOptionParser;
use OTelDistroTests\Util\Config\EnumOptionParser;
use OTelDistroTests\Util\Config\FloatOptionMetadata;
use OTelDistroTests\Util\Config\FloatOptionParser;
use OTelDistroTests\Util\Config\IntOptionParser;
use OTelDistroTests\Util\Config\OptionMetadata;
use OTelDistroTests\Util\Config\OptionParser;
use OTelDistroTests\Util\Config\OptionsForTestsMetadata;
use OTelDistroTests\Util\Config\ParseException;
use OTelDistroTests\Util\Config\Parser;
use OTelDistroTests\Util\Config\StringOptionParser;
use OTelDistroTests\Util\Config\WildcardListOptionParser;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\Duration;
use OTelDistroTests\Util\DurationUnit;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\RandomUtil;
use OTelDistroTests\Util\RangeUtil;
use OTelDistroTests\Util\TestCaseBase;

class VariousOptionsParsingTest extends TestCaseBase
{
    /**
     * @param OptionMetadata<mixed> $optMeta
     *
     * @return OptionTestValuesGeneratorInterface<mixed>
     */
    private static function selectTestValuesGenerator(OptionMetadata $optMeta): OptionTestValuesGeneratorInterface
    {
        $optionParser = $optMeta->parser();

        if ($optionParser instanceof BoolOptionParser) {
            return new EnumOptionTestValuesGenerator($optionParser, additionalValidValues: [new OptionTestValidValue('', false)]); /** @phpstan-ignore return.type */
        }
        if ($optionParser instanceof DurationOptionParser) {
            return new DurationOptionTestValuesGenerator($optionParser); /** @phpstan-ignore return.type */
        }
        if ($optionParser instanceof EnumOptionParser) {
            return new EnumOptionTestValuesGenerator($optionParser);
        }
        if ($optionParser instanceof FloatOptionParser) {
            return new FloatOptionTestValuesGenerator($optionParser); /** @phpstan-ignore return.type */
        }
        if ($optionParser instanceof IntOptionParser) {
            return new IntOptionTestValuesGenerator($optionParser); /** @phpstan-ignore return.type */
        }
        if ($optionParser instanceof StringOptionParser) {
            return StringOptionTestValuesGenerator::singletonInstance(); /** @phpstan-ignore return.type */
        }
        if ($optionParser instanceof WildcardListOptionParser) {
            return WildcardListOptionTestValuesGenerator::singletonInstance(); /** @phpstan-ignore return.type */
        }

        self::fail('Unknown option metadata type: ' . get_debug_type($optMeta));
    }

    /**
     * @return array<string, OptionMetadata<mixed>>
     */
    private static function additionalOptionMetas(): array
    {
        $result = [];

        $result['Duration s units'] = new DurationOptionMetadata(
            new Duration(10, DurationUnit::ms) /* minValidValue */,
            new Duration(20, DurationUnit::ms) /* maxValidValue */,
            DurationUnit::s /* <- defaultUnits */,
            new Duration(15, DurationUnit::ms) /* <- defaultValue */
        );

        $result['Duration m units'] = new DurationOptionMetadata(
            null /* minValidValue */,
            null /* maxValidValue */,
            DurationUnit::m /* <- defaultUnits */,
            new Duration(123, DurationUnit::m) /* <- defaultValue */
        );

        $result['Float without constrains'] = new FloatOptionMetadata(
            null /* minValidValue */,
            null /* maxValidValue */,
            123.321 /* defaultValue */
        );

        $result['Float only with min constrain'] = new FloatOptionMetadata(
            -1.0 /* minValidValue */,
            null /* maxValidValue */,
            456.789 /* defaultValue */
        );

        $result['Float only with max constrain'] = new FloatOptionMetadata(
            null /* minValidValue */,
            1.0 /* maxValidValue */,
            -987.654 /* defaultValue */
        );

        /** @var array<string, OptionMetadata<mixed>> $result */
        return $result; // @phpstan-ignore varTag.nativeType
    }

    /**
     * @return list<array<string, OptionMetadata<mixed>>>
     */
    private static function snapshotClassToOptionsMeta(): array
    {
        return [
            OptionsForTestsMetadata::get(),
            self::additionalOptionMetas(),
        ];
    }

    /**
     * @return iterable<array{string, OptionMetadata<mixed>}>
     */
    public static function allOptionsMetadataProvider(): iterable
    {
        foreach (self::snapshotClassToOptionsMeta() as $optionsMeta) {
            foreach ($optionsMeta as $optMeta) {
                if (!$optMeta->parser() instanceof CustomOptionParser) {
                    yield [LoggableToString::convert($optMeta), $optMeta];
                }
            }
        }
    }

    /**
     * @return iterable<array{string, OptionMetadata<mixed>}>
     */
    public static function allOptionsMetadataWithPossibleInvalidRawValuesProvider(): iterable
    {
        foreach (self::allOptionsMetadataProvider() as $optMetaDescAndDataPair) {
            /** @var OptionMetadata<mixed> $optMeta */
            $optMeta = $optMetaDescAndDataPair[1];
            if (!IterableUtil::isEmpty(self::selectTestValuesGenerator($optMeta)->invalidRawValues())) {
                yield $optMetaDescAndDataPair;
            }
        }
    }

    public function testIntOptionParserIsValidFormat(): void
    {
        self::assertTrue(IntOptionParser::isValidFormat('0'));
        self::assertFalse(IntOptionParser::isValidFormat('0.0'));
        self::assertTrue(IntOptionParser::isValidFormat('+0'));
        self::assertFalse(IntOptionParser::isValidFormat('+0.0'));
        self::assertTrue(IntOptionParser::isValidFormat('-0'));
        self::assertFalse(IntOptionParser::isValidFormat('-0.0'));

        self::assertTrue(IntOptionParser::isValidFormat('1'));
        self::assertFalse(IntOptionParser::isValidFormat('1.0'));
        self::assertTrue(IntOptionParser::isValidFormat('+1'));
        self::assertFalse(IntOptionParser::isValidFormat('+1.0'));
        self::assertTrue(IntOptionParser::isValidFormat('-1'));
        self::assertFalse(IntOptionParser::isValidFormat('-1.0'));
    }

    /**
     * @param OptionTestValuesGeneratorInterface<mixed> $testValuesGenerator
     * @param OptionParser<mixed>                       $optParser
     */
    public static function parseInvalidValueTestImpl(OptionTestValuesGeneratorInterface $testValuesGenerator, OptionParser $optParser): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $invalidRawValues = $testValuesGenerator->invalidRawValues();
        if (IterableUtil::isEmpty($invalidRawValues)) {
            self::dummyAssert();
            return;
        }
        $dbgCtx->add(compact('invalidRawValues'));

        foreach ($invalidRawValues as $invalidRawValue) {
            $invalidRawValue = self::genOptionalWhitespace() . $invalidRawValue . self::genOptionalWhitespace();
            $dbgCtx->add([...compact('invalidRawValue'), 'strlen($invalidRawValue)' => strlen($invalidRawValue)]);
            if (!TextUtil::isEmptyString($invalidRawValue)) {
                $dbgCtx->add(['ord($invalidRawValue[0])' => ord($invalidRawValue[0])]);
            }
            AssertEx::throws(
                ParseException::class,
                function () use ($optParser, $invalidRawValue): void {
                    Parser::parseOptionRawValue($invalidRawValue, $optParser);
                }
            );
        }
    }

    /**
     * @dataProvider allOptionsMetadataWithPossibleInvalidRawValuesProvider
     *
     * @param OptionMetadata<mixed> $optMeta
     */
    public function testParseInvalidValue(string $optMetaDbgDesc, OptionMetadata $optMeta): void
    {
        self::parseInvalidValueTestImpl(self::selectTestValuesGenerator($optMeta), $optMeta->parser());
    }

    private static function genOptionalWhitespace(): string
    {
        $whiteSpaceChars = [' ', "\t"];
        $result = '';
        foreach (RangeUtil::generateUpTo(3) as $ignored) {
            $result .= RandomUtil::getRandomValueFromArray($whiteSpaceChars);
        }
        return $result;
    }

    /**
     * @param OptionTestValuesGeneratorInterface<mixed> $testValuesGenerator
     * @param OptionParser<mixed>                       $optParser
     */
    public static function parseValidValueTestImpl(OptionTestValuesGeneratorInterface $testValuesGenerator, OptionParser $optParser): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $validValues = $testValuesGenerator->validValues();
        if (IterableUtil::isEmpty($validValues)) {
            self::dummyAssert();
            return;
        }
        $dbgCtx->add(['validValues' => $validValues]);

        $valueWithDetails = function (mixed $value): mixed {
            if (!is_float($value)) {
                return $value;
            }

            return ['$value' => $value, 'number_format($value)' => number_format($value)];
        };

        /** @var OptionTestValidValue<mixed> $validValueData */
        foreach ($validValues as $validValueData) {
            $dbgCtx->add(['validValueData' => $validValueData, '$validValueData->parsedValue' => $valueWithDetails($validValueData->parsedValue)]);
            $validValueData->rawValue = self::genOptionalWhitespace() . $validValueData->rawValue . self::genOptionalWhitespace();
            $actualParsedValue = Parser::parseOptionRawValue($validValueData->rawValue, $optParser);
            $dbgCtx->add(['actualParsedValue' => $valueWithDetails($actualParsedValue)]);
            AssertEx::equalsEx($validValueData->parsedValue, $actualParsedValue);
        }
    }

    /**
     * @dataProvider allOptionsMetadataProvider
     *
     * @param OptionMetadata<mixed> $optMeta
     */
    public function testParseValidValue(string $optMetaDbgDesc, OptionMetadata $optMeta): void
    {
        self::parseValidValueTestImpl(self::selectTestValuesGenerator($optMeta), $optMeta->parser());
    }
}
