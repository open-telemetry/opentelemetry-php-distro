<?php

declare(strict_types=1);

namespace OpenTelemetry\Distro\Util;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class WildcardMatcher
{
    private const CASE_SENSITIVE_PREFIX = '(?-i)';
    private const WILDCARD = '*';

    private static int $wildcardLen;
    private bool $isCaseSensitive;
    private bool $startsWithWildcard;
    private bool $endsWithWildcard;
    /** @var string[] */
    private array $literalParts;

    public function __construct(string $expr)
    {
        if (!isset(self::$wildcardLen)) {
            self::$wildcardLen = strlen(self::WILDCARD);
        }

        $this->isCaseSensitive = TextUtil::isPrefixOf(self::CASE_SENSITIVE_PREFIX, $expr);
        $exprPos = $this->isCaseSensitive ? strlen(self::CASE_SENSITIVE_PREFIX) : 0;
        $exprLen = strlen($expr);
        $this->literalParts = [];
        $this->startsWithWildcard = false;
        $lastPartWasWildcard = false;
        while ($exprPos < $exprLen) {
            $nextWildcardPos = strpos($expr, self::WILDCARD, $exprPos);
            if ($nextWildcardPos === $exprPos) {
                $lastPartWasWildcard = true;
                if ($this->literalParts === []) {
                    $this->startsWithWildcard = true;
                }
                $exprPos += self::$wildcardLen;
                continue;
            }

            $lastPartWasWildcard = false;
            $literalPartEndPos = ($nextWildcardPos === false) ? $exprLen : $nextWildcardPos;
            $literalPartLen = $literalPartEndPos - $exprPos;
            $this->literalParts[] = substr($expr, offset: $exprPos, length: $literalPartLen);
            $exprPos += $literalPartLen;
        }
        $this->endsWithWildcard = $lastPartWasWildcard;
    }

    private static function findSubString(string $haystack, string $needle, int $offset, bool $isCaseSensitive): false|int
    {
        return $isCaseSensitive ? strpos($haystack, $needle, $offset) : stripos($haystack, $needle, $offset);
    }

    private static function areStringsEqual(string $str1, string $str2, bool $isCaseSensitive): bool
    {
        return $isCaseSensitive ? (strcmp($str1, $str2) === 0) : (strcasecmp($str1, $str2) === 0);
    }

    public function match(string $text): bool
    {
        if (!$this->startsWithWildcard && $this->literalParts === [] && $text !== '') {
            return false;
        }

        $allowAnyPrefix = $this->startsWithWildcard;
        $textPos = 0;
        $numberOfPartsToCheckInLoop = count($this->literalParts);
        if ($numberOfPartsToCheckInLoop > 0 && !$this->endsWithWildcard) {
            --$numberOfPartsToCheckInLoop;
        }
        for ($i = 0; $i < $numberOfPartsToCheckInLoop; ++$i) {
            $currentLiteralPart = $this->literalParts[$i];
            $literalPartMatchPos = self::findSubString($text, $currentLiteralPart, $textPos, $this->isCaseSensitive);
            if ($literalPartMatchPos === false) {
                return false;
            }
            if (!$allowAnyPrefix && $literalPartMatchPos !== $textPos) {
                return false;
            }
            $textPos += strlen($currentLiteralPart);
            $allowAnyPrefix = true;
        }
        if ($numberOfPartsToCheckInLoop < count($this->literalParts)) {
            $lastPart = $this->literalParts[count($this->literalParts) - 1];
            if (!$this->startsWithWildcard && count($this->literalParts) === 1) {
                if (!self::areStringsEqual($lastPart, $text, $this->isCaseSensitive)) {
                    return false;
                }
            } else {
                if (!TextUtil::isSuffixOf($lastPart, $text, $this->isCaseSensitive)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function groupName(): string
    {
        $result = '';

        if ($this->startsWithWildcard) {
            $result .= self::WILDCARD;
        }

        $isFirstLiteralPart = true;
        foreach ($this->literalParts as $literalPart) {
            if ($isFirstLiteralPart) {
                $isFirstLiteralPart = false;
            } else {
                $result .= self::WILDCARD;
            }
            $result .= $literalPart;
        }

        if ($this->endsWithWildcard) {
            $result .= self::WILDCARD;
        }

        return $result;
    }

    public function __toString(): string
    {
        $result = $this->groupName();

        if ($this->isCaseSensitive) {
            $result = self::CASE_SENSITIVE_PREFIX . $result;
        }

        return $result;
    }
}
