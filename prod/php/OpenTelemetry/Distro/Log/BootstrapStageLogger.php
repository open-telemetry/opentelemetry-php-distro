<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\Log;

use Closure;
use OpenTelemetry\Distro\Util\GetContextInterface;
use OpenTelemetry\Distro\Util\StaticClassTrait;

/**
 * @phpstan-import-type Context from GetContextInterface
 *
 * @phpstan-type FormatAndWrite Closure(int $level, int $prodLogFeature, string $file, int $line, string $func, string $message, Context $context): void
 */
final class BootstrapStageLogger
{
    use StaticClassTrait;

    public const LEVEL_OFF = 0;
    public const LEVEL_CRITICAL = 1;
    public const LEVEL_ERROR = 2;
    public const LEVEL_WARNING = 3;
    public const LEVEL_INFO = 4;
    public const LEVEL_DEBUG = 5;
    public const LEVEL_TRACE = 6;

    private const LEVEL_AS_STRING = [
        self::LEVEL_OFF => 'OFF',
        self::LEVEL_CRITICAL => 'CRITICAL',
        self::LEVEL_ERROR => 'ERROR',
        self::LEVEL_WARNING => 'WARNING',
        self::LEVEL_INFO => 'INFO',
        self::LEVEL_DEBUG => 'DEBUG',
        self::LEVEL_TRACE => 'TRACE',
    ];

    private static int $maxEnabledLevel = self::LEVEL_OFF;

    /** @var ?Closure */
    private static ?Closure $formatAndWrite = null;

    /** @var list<string> */
    private static array $srcCodePathPrefixesToRemove;

    /**
     * @phpstan-param ?Closure $formatAndWrite
     */
    public static function configure(
        int $maxEnabledLevel,
        array $srcCodeRootDirsToRemove,
        ?Closure $formatAndWrite = null
    ): void {
        self::$maxEnabledLevel = $maxEnabledLevel;
        self::$formatAndWrite = $formatAndWrite;

        $srcCodePathPrefixesToRemove = [];
        foreach ($srcCodeRootDirsToRemove as $srcCodeRootDirToRemove) {
            $srcCodePathPrefixesToRemove[] = $srcCodeRootDirToRemove . DIRECTORY_SEPARATOR;
        }
        self::$srcCodePathPrefixesToRemove = $srcCodePathPrefixesToRemove;

        $maxEnabledLevelAsString = self::levelIntToString($maxEnabledLevel);
        $formatAndWriteIsNull = ($formatAndWrite === null);
        $ctx = compact('maxEnabledLevelAsString', 'maxEnabledLevel', 'srcCodeRootDirsToRemove', 'srcCodePathPrefixesToRemove', 'formatAndWriteIsNull');
        self::logDebug(__FILE__, __LINE__, __FUNCTION__, 'Exiting...', $ctx);
    }

    public static function levelIntToString(int $level): string
    {
        if (array_key_exists($level, self::LEVEL_AS_STRING)) {
            return self::LEVEL_AS_STRING[$level];
        }

        return "LEVEL $level";
    }

    public static function levelStringToInt(string $levelString): ?int
    {
        /** @var ?array<string, int> $levelStringToInt */
        static $levelStringToInt = null;
        if ($levelStringToInt === null) {
            $levelStringToInt = [];
            foreach (self::LEVEL_AS_STRING as $currLevelInt => $currLevelString) {
                $levelStringToInt[strtoupper($currLevelString)] = $currLevelInt;
            }
        }

        $levelStringUpper = strtoupper($levelString);
        return array_key_exists($levelStringUpper, $levelStringToInt) ? $levelStringToInt[$levelStringUpper] : null;
    }

    public static function nullableToLog(null|int|string $str): string
    {
        return $str === null ? 'null' : strval($str);
    }

    public static function isEnabledForLevel(int $statementLevel): bool
    {
        return $statementLevel <= self::$maxEnabledLevel;
    }

    private static function isPrefixOf(string $prefix, string $text, bool $isCaseSensitive = true): bool
    {
        $prefixLen = strlen($prefix);
        if ($prefixLen === 0) {
            return true;
        }

        return substr_compare(
            $text /* <- haystack */,
            $prefix /* <- needle */,
            0 /* <- offset */,
            $prefixLen /* <- length */,
            !$isCaseSensitive /* <- case_insensitivity */
        ) === 0;
    }

    private static function processSourceCodeFilePathForLog(string $srcCodeFilePath): string
    {
        foreach (self::$srcCodePathPrefixesToRemove as $srcCodePathPrefixToRemove) {
            if (self::isPrefixOf($srcCodePathPrefixToRemove, $srcCodeFilePath, /* isCaseSensitive: */ false)) {
                return substr($srcCodeFilePath, strlen($srcCodePathPrefixToRemove));
            }
        }
        return $srcCodeFilePath;
    }

    /**
     * @phpstan-param Context $context
     *
     * @see packaging/test/smokeTest.php
    */
    public static function logDebug(string $file, int $line, string $func, string $message, array $context = []): ?EnabledBootstrapStageLoggerProxy
    {
        self::logWithFeatureAndLevel($file, $line, $func, LogFeature::BOOTSTRAP, self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * @phpstan-param Context $context
     */
    public static function logWithFeatureAndLevel(string $file, int $line, string $func, int $feature, int $statementLevel, string $message, array $context = []): void
    {
        if (!self::isEnabledForLevel($statementLevel)) {
            return;
        }

        if (self::$formatAndWrite === null) {
            /**
             * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
             *
             * @noinspection PhpFullyQualifiedNameUsageInspection
             */
            \OpenTelemetry\Distro\log_feature(
                0 /* $isForced */,
                $statementLevel,
                $feature,
                self::processSourceCodeFilePathForLog($file),
                $line,
                $func,
                BuildToolsLog::concatMessageAndContext($message, $context)
            );
        } else {
            (self::$formatAndWrite)(
                $statementLevel,
                $feature,
                self::processSourceCodeFilePathForLog($file),
                $line,
                $func,
                $message
            );
        }
    }

    /**
     * @noinspection PhpUnused
     */
    public static function possiblySecuritySensitive(mixed $value): mixed
    {
        return self::isEnabledForLevel(self::LEVEL_TRACE) ? $value : 'REDACTED (POSSIBLY SECURITY SENSITIVE) DATA';
    }
}
