<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests\ConfigTests;

use OpenTelemetry\Distro\Util\TextUtil;
use OTelDistroTests\Util\Config\EnumOptionParser;
use OTelDistroTests\Util\RandomUtil;
use OTelDistroTests\Util\RangeUtil;
use OTelDistroTests\Util\TextUtilForTests;
use Override;

/**
 * @template T
 *
 * @implements OptionTestValuesGeneratorInterface<T>
 */
final class EnumOptionTestValuesGenerator implements OptionTestValuesGeneratorInterface
{
    /** @var EnumOptionParser<T> */
    private EnumOptionParser $optionParser;

    /** @var array<OptionTestValidValue<T>> */
    private array $additionalValidValues;

    /** @var array<string> */
    private array $additionalInvalidRawValues;

    /**
     * EnumOptionTestValuesGenerator constructor.
     *
     * @param EnumOptionParser<T>            $optionParser
     * @param array<OptionTestValidValue<T>> $additionalValidValues
     * @param array<string>                  $additionalInvalidRawValues
     */
    public function __construct(
        EnumOptionParser $optionParser,
        array $additionalValidValues = [],
        array $additionalInvalidRawValues = []
    ) {
        $this->optionParser = $optionParser;
        $this->additionalValidValues = $additionalValidValues;
        $this->additionalInvalidRawValues = $additionalInvalidRawValues;
    }

    private static function flipRandomLetters(string $srcStr, int $numberOfLettersToFlip): string
    {
        if ($numberOfLettersToFlip === 0) {
            return $srcStr;
        }

        /** @var int[] $letterIndexes */
        $letterIndexes = [];
        foreach (RangeUtil::generateUpTo(strlen($srcStr)) as $charIndex) {
            if (TextUtilForTests::isLetter(ord($srcStr[$charIndex]))) {
                $letterIndexes[] = $charIndex;
            }
        }

        $actualNumberOfLettersToFlip = min($numberOfLettersToFlip, count($letterIndexes));
        $letterToFlipIndexes = RandomUtil::arrayRandValues($letterIndexes, $actualNumberOfLettersToFlip);

        $result = '';
        $remainderStartIndex = 0;
        foreach ($letterToFlipIndexes as $letterToFlipIndex) {
            $result .= substr($srcStr, $remainderStartIndex, $letterToFlipIndex - $remainderStartIndex);
            $result .= chr(TextUtilForTests::flipLetterCase(ord($srcStr[$letterToFlipIndex])));
            $remainderStartIndex = $letterToFlipIndex + 1;
        }
        $result .= substr($srcStr, $remainderStartIndex);

        return $result;
    }

    /**
     * @param string $enumEntryName
     *
     * @return iterable<string>
     */
    private function genCaseVariations(string $enumEntryName): iterable
    {
        $maxNumberOfLettersToFlip = $this->optionParser->isCaseSensitive() ? 0 : 2;
        foreach (RangeUtil::generateFromToIncluding(0, $maxNumberOfLettersToFlip) as $numberOfLettersToFlip) {
            yield self::flipRandomLetters($enumEntryName, $numberOfLettersToFlip);
        }
    }

    private function isUnambiguousPrefix(string $prefix): bool
    {
        $foundMatchingEntry = false;
        foreach ($this->optionParser->nameValuePairs() as $enumEntryNameValuePair) {
            if (TextUtil::isPrefixOf($prefix, $enumEntryNameValuePair[0], $this->optionParser->isCaseSensitive())) {
                if ($foundMatchingEntry) {
                    return false;
                }
                $foundMatchingEntry = true;
            }
        }
        return $foundMatchingEntry;
    }

    /**
     * @param string $enumEntryName
     *
     * @return iterable<string>
     */
    private function genPrefixVariations(string $enumEntryName): iterable
    {
        yield $enumEntryName;

        if (!$this->optionParser->isUnambiguousPrefixAllowed()) {
            return;
        }

        foreach (RangeUtil::generateFromToIncluding(1, strlen($enumEntryName) - 1) as $lengthToCutOff) {
            $prefix = substr($enumEntryName, 0, -$lengthToCutOff);
            if ($this->isUnambiguousPrefix($prefix)) {
                yield $prefix;
            } else {
                break;
            }
        }
    }

    public function validValues(): iterable
    {
        yield from $this->additionalValidValues;

        foreach ($this->optionParser->nameValuePairs() as $enumEntryNameAndValue) {
            foreach ($this->genPrefixVariations($enumEntryNameAndValue[0]) as $enumEntryNamePrefix) {
                foreach ($this->genCaseVariations($enumEntryNamePrefix) as $manipulatedEnumEntryName) {
                    yield new OptionTestValidValue($manipulatedEnumEntryName, $enumEntryNameAndValue[1]);
                }
            }
        }
    }

    private function isValidRawValue(string $rawValue): bool
    {
        foreach ($this->additionalValidValues as $additionalValidValue) {
            $trimmedRawValue = trim($rawValue);
            if ($trimmedRawValue === $additionalValidValue->rawValue) {
                return true;
            }
        }

        $foundAsPrefix = false;
        foreach ($this->optionParser->nameValuePairs() as $enumEntryNameAndValue) {
            if (TextUtil::isPrefixOf($rawValue, $enumEntryNameAndValue[0], $this->optionParser->isCaseSensitive())) {
                if (strlen($rawValue) === strlen($enumEntryNameAndValue[0])) {
                    return true;
                }
                if ($foundAsPrefix) {
                    return false;
                }
                $foundAsPrefix = true;
            }
        }
        return $foundAsPrefix;
    }

    /**
     * @return iterable<string>
     */
    private function invalidRawValuesImpl(): iterable
    {
        /**
         * @param string $rawValue
         *
         * @return iterable<string>
         */
        $genIfNotValidRawValue = function (string $rawValue): iterable {
            if (!$this->isValidRawValue($rawValue)) {
                yield $rawValue;
            }
        };

        yield from $this->additionalInvalidRawValues;

        yield from ['', ' ', '\t', '\r\n'];

        /** @var OptionTestValidValue<string> $validValueData */
        foreach (StringOptionTestValuesGenerator::singletonInstance()->validValues() as $validValueData) {
            yield from $genIfNotValidRawValue($validValueData->parsedValue);
        }

        foreach ($this->optionParser->nameValuePairs() as $enumEntryNameAndValue) {
            $lengthsToCutOffVars = RangeUtil::generateFromToIncluding(0, strlen($enumEntryNameAndValue[0]) - 1);
            foreach ($lengthsToCutOffVars as $lengthToCutOff) {
                $prefixBeforeCaseVariations = substr($enumEntryNameAndValue[0], 0, -$lengthToCutOff);
                foreach ($this->genCaseVariations($prefixBeforeCaseVariations) as $prefix) {
                    yield from $genIfNotValidRawValue($prefix);
                    yield from $genIfNotValidRawValue($prefix . '_X');
                    yield from $genIfNotValidRawValue('X_' . $prefix);
                }
            }
        }
    }

    /** @inheritDoc */
    #[Override]
    public function invalidRawValues(): iterable
    {
        foreach ($this->invalidRawValuesImpl() as $invalidRawValue) {
            if (!$this->isValidRawValue($invalidRawValue)) {
                yield $invalidRawValue;
            }
        }
    }
}
