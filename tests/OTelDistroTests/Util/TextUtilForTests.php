<?php

declare(strict_types=1);

namespace OTelDistroTests\Util;

use OpenTelemetry\Distro\Util\NumericUtil;
use OpenTelemetry\Distro\Util\StaticClassTrait;
use UnexpectedValueException;

/**
 * @phpstan-type UnsignedByte int<0, 255>
 */
final class TextUtilForTests
{
    use StaticClassTrait;

    private const CR_AS_INT = 13;
    private const LF_AS_INT = 10;

    /**
     * @return iterable<int>
     */
    public static function iterateOverChars(string $input): iterable
    {
        foreach (RangeUtil::generateUpTo(strlen($input)) as $i) {
            yield ord($input[$i]);
        }
    }

    private static function ifEndOfLineSeqGetLength(string $text, int $textLen, int $index): int
    {
        $charAsInt = ord($text[$index]);
        if ($charAsInt === self::CR_AS_INT && $index != ($textLen - 1) && ord($text[$index + 1]) === self::LF_AS_INT) {
            return 2;
        }
        if ($charAsInt === self::CR_AS_INT || $charAsInt === self::LF_AS_INT) {
            return 1;
        }
        return 0;
    }

    /**
     * @param string $text
     *
     * @return iterable<array{string, string}>
     *                                ^^^^^^----- end-of-line (empty for the last line)
     *                        ^^^^^^------------- line text without end-of-line
     */
    public static function iterateLinesEx(string $text): iterable
    {
        $lineStartPos = 0;
        $currentPos = $lineStartPos;
        $textLen = strlen($text);
        for (; $currentPos != $textLen;) {
            $endOfLineSeqLength = self::ifEndOfLineSeqGetLength($text, $textLen, $currentPos);
            if ($endOfLineSeqLength === 0) {
                ++$currentPos;
                continue;
            }
            yield [substr($text, $lineStartPos, $currentPos - $lineStartPos) /* <- line text without end-of-line */, substr($text, $currentPos, $endOfLineSeqLength) /* <- end-of-line */];
            $lineStartPos = $currentPos + $endOfLineSeqLength;
            $currentPos = $lineStartPos;
        }

        yield [substr($text, $lineStartPos, $currentPos - $lineStartPos), '' /* <- end-of-line is always empty for the last line */];
    }

    /**
     * @param string $text
     * @param bool   $keepEndOfLine
     *
     * @return iterable<string>
     */
    public static function iterateLines(string $text, bool $keepEndOfLine = false): iterable
    {
        foreach (self::iterateLinesEx($text) as [$lineText, $endOfLine]) {
            yield $lineText . ($keepEndOfLine ? $endOfLine : '');
        }
    }

    public static function prefixEachLine(string $text, string $prefix): string
    {
        $result = '';
        foreach (self::iterateLines($text, keepEndOfLine: true) as $line) {
            $result .= $prefix . $line;
        }
        return $result;
    }

    public static function contains(string $haystack, string $needle): bool
    {
        return str_contains($haystack, $needle);
    }

    /** @noinspection PhpUnused */
    public static function combineWithSeparatorIfNotEmpty(string $separator, string $partToAppend): string
    {
        return ($partToAppend === '' ? '' : $separator) . $partToAppend;
    }

    /**
     * @param null|int|float|string $input
     *
     * @noinspection PhpUnused
     */
    public static function strvalEmptyIfNull(mixed $input): string
    {
        return $input === null ? '' : strval($input);
    }

    public static function removeIndentation(string $input): string
    {
        $indentationChars = " \t";
        $indentationLen = strspn($input, $indentationChars);
        if ($indentationLen === 0) {
            return $input;
        }
        $indentation = substr($input, offset: 0, length: $indentationLen);

        $result = '';
        foreach (self::iterateLinesEx($input) as [$line, $endOfLine]) {
            if ($line !== '' && !str_starts_with(haystack: $line, needle: $indentation)) {
                throw new UnexpectedValueException(ExceptionUtil::buildMessage('Line does not start with expected indentation', compact('line', 'indentation', 'indentationLen', 'input')));
            }
            $result .= substr($line, offset: $indentationLen) . $endOfLine;
        }
        return $result;
    }

    /** @noinspection PhpUnused */
    public static function ensureMaxLength(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }
        return substr($text, /* start: */ 0, /* length: */ $maxLength);
    }

    /** @noinspection PhpUnused */
    public static function isNullOrEmptyString(?string $str): bool
    {
        return ($str === null) || ($str === '');
    }

    /**
     * @phpstan-param UnsignedByte $charAsInt
     */
    public static function isUpperCaseLetter(int $charAsInt): bool
    {
        return NumericUtil::isInClosedInterval(ord('A'), $charAsInt, ord('Z'));
    }

    /**
     * @phpstan-param UnsignedByte $charAsInt
     */
    public static function isLowerCaseLetter(int $charAsInt): bool
    {
        return NumericUtil::isInClosedInterval(ord('a'), $charAsInt, ord('z'));
    }

    /**
     * @phpstan-param UnsignedByte $charAsInt
     *
     * @noinspection PhpUnused
     */
    public static function isLetter(int $charAsInt): bool
    {
        return self::isUpperCaseLetter($charAsInt) || self::isLowerCaseLetter($charAsInt);
    }

    /**
     * @param UnsignedByte $charAsInt
     *
     * @return UnsignedByte
     */
    public static function toLowerCaseLetter(int $charAsInt): int
    {
        if (self::isUpperCaseLetter($charAsInt)) {
            return (($charAsInt - ord('A')) + ord('a')); // @phpstan-ignore return.type
        }
        return $charAsInt;
    }

    /**
     * @param UnsignedByte $charAsInt
     *
     * @return UnsignedByte
     */
    public static function toUpperCaseLetter(int $charAsInt): int
    {
        if (self::isLowerCaseLetter($charAsInt)) {
            return (($charAsInt - ord('a')) + ord('A')); // @phpstan-ignore return.type
        }
        return $charAsInt;
    }

    /**
     * @param UnsignedByte $charAsInt
     *
     * @return UnsignedByte
     */
    public static function flipLetterCase(int $charAsInt): int
    {
        return self::isUpperCaseLetter($charAsInt) ? self::toLowerCaseLetter($charAsInt) : self::toUpperCaseLetter($charAsInt);
    }

    public static function camelToSnakeCase(string $input): string
    {
        $inputLen = strlen($input);
        $result = '';
        $prevIndex = 0;
        for ($i = 0; $i != $inputLen; ++$i) {
            $currentCharAsInt = ord($input[$i]);
            if (!self::isUpperCaseLetter($currentCharAsInt)) {
                continue;
            }
            $result .= substr($input, $prevIndex, $i - $prevIndex);
            if ($i !== 0) {
                $result .= '_';
            }
            $result .= chr(self::toLowerCaseLetter($currentCharAsInt));
            $prevIndex = $i + 1;
        }
        if ($result === '') {
            return $input;
        }

        $result .= substr($input, $prevIndex, $inputLen - $prevIndex);
        return $result;
    }

    public static function snakeToCamelCase(string $input): string
    {
        $inputLen = strlen($input);
        $result = '';
        $inputRemainderPos = 0;
        while (true) {
            $underscorePos = strpos($input, '_', $inputRemainderPos);
            if ($underscorePos === false) {
                break;
            }

            $result .= substr($input, $inputRemainderPos, $underscorePos - $inputRemainderPos);

            $nonUnderscorePos = null;
            for ($i = $underscorePos; $i !== $inputLen; ++$i) {
                if ($input[$i] !== '_') {
                    $nonUnderscorePos = $i;
                    break;
                }
            }

            if ($nonUnderscorePos === null) {
                $inputRemainderPos = strlen($input);
                break;
            }

            // Don't uppercase the first letter
            if ($result === '') {
                $result .= $input[$nonUnderscorePos];
            } else {
                $result .= chr(self::toUpperCaseLetter(ord($input[$nonUnderscorePos])));
            }
            if ($nonUnderscorePos === $inputRemainderPos - 1) {
                break;
            }
            $inputRemainderPos = $nonUnderscorePos + 1;
        }

        if ($inputRemainderPos === strlen($input)) {
            return $result;
        }

        if ($result === '') {
            return $input;
        }

        return $result . substr($input, $inputRemainderPos, $inputLen - $inputRemainderPos);
    }

    /**
     * Convert camel case ('someText') to Pascal case ('SomeText')
     *
     * @param string $input
     *
     * @return string
     *
     * @noinspection PhpUnused
     */
    public static function camelToPascalCase(string $input): string
    {
        if ($input === '') {
            return '';
        }
        return chr(self::toUpperCaseLetter(ord($input[0]))) . substr($input, 1, strlen($input) - 1);
    }

    public static function appendWithOptionalSeparator(string $base, string $separator, string $suffix): string
    {
        $result = $base;
        if ($result !== '') {
            $result .= $separator;
        }
        $result .= $suffix;
        return $result;
    }
}
