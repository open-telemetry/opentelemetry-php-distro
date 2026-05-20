<?php

declare(strict_types=1);

namespace OTelDistroTests\Util;

use OpenTelemetry\Distro\Util\StaticClassTrait;
use UnexpectedValueException;

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
    public static function iterateLines(string $text, bool $keepEndOfLine): iterable
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
}
