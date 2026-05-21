<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\Util;

final class TextUtil
{
    use StaticClassTrait;

    public static function isPrefixOf(string $prefix, string $text, bool $isCaseSensitive = true): bool
    {
        $prefixLen = strlen($prefix);
        if ($prefixLen === 0) {
            return true;
        }

        return substr_compare(/* haystack: */ $text, /* needle: */ $prefix, /* offset: */ 0, /* length: */ $prefixLen, /* case_insensitive: */ !$isCaseSensitive) === 0;
    }

    /** @noinspection PhpUnused */
    public static function isPrefixOfIgnoreCase(string $prefix, string $text): bool
    {
        return self::isPrefixOf($prefix, $text, isCaseSensitive: false);
    }

    public static function isSuffixOf(string $suffix, string $text, bool $isCaseSensitive = true): bool
    {
        $suffixLen = strlen($suffix);
        if ($suffixLen === 0) {
            return true;
        }

        return substr_compare(/* haystack: */ $text, /* needle: */ $suffix, /* offset: */ -$suffixLen, /* length: */ $suffixLen, /* case_insensitive: */ !$isCaseSensitive) == 0;
    }

    /** @noinspection PhpUnused */
    public static function isSuffixOfIgnoreCase(string $prefix, string $text): bool
    {
        return self::isSuffixOf($prefix, $text, isCaseSensitive: false);
    }
}
