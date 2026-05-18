<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\DistroTools\Build;

use Closure;
use DateTime;
use OpenTelemetry\Distro\Log\LogFeature;
use OpenTelemetry\Distro\Log\LogLevel;
use ReflectionClass;
use Throwable;

/**
 * @phpstan-type Context array<string, mixed>
 * @phpstan-type FormatAndWrite Closure(LogLevel $level, string $file, int $line, string $func, string $message, Context $context): void
 */
final class BuildToolsLog
{
    use BuildToolsAssertTrait;

    private const LOG_LINE_PREFIX = '[OTel PHP Distro build tool]';
    public const DEFAULT_LEVEL = LogLevel::debug;

    private static ?LogLevel $maxEnabledLevel = null;

    /** @var ?FormatAndWrite */
    private static ?Closure $formatAndWrite = null;

    public static function configure(LogLevel $maxEnabledLevel, ?Closure $formatAndWrite = null): void
    {
        self::assertNull(self::$maxEnabledLevel);
        self::$maxEnabledLevel = $maxEnabledLevel;
        self::$formatAndWrite = $formatAndWrite;
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnused
     */
    public static function error(string $file, int $line, string $fqMethod, string $message, array $context = []): void
    {
        self::withLevel(LogLevel::error, $file, $line, $fqMethod, $message, $context);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnused
     */
    public static function info(string $file, int $line, string $fqMethod, string $message, array $context = []): void
    {
        self::withLevel(LogLevel::info, $file, $line, $fqMethod, $message, $context);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnused
     */
    public static function debug(string $file, int $line, string $fqMethod, string $message, array $context = []): void
    {
        self::withLevel(LogLevel::debug, $file, $line, $fqMethod, $message, $context);
    }

    /**
     * @param Context $context
     *
     * @noinspection PhpUnused
     */
    public static function trace(string $file, int $line, string $fqMethod, string $message, array $context = []): void
    {
        self::withLevel(LogLevel::trace, $file, $line, $fqMethod, $message, $context);
    }

    /**
     * @param Context $context
     */
    public static function withLevel(LogLevel $level, string $file, int $line, string $fqMethod, string $message, array $context = []): void
    {
        if (!self::isLevelIntEnabled($level->value)) {
            return;
        }

        if (self::$formatAndWrite === null) {
            self::defaultFormatAndWrite(
                levelString: strtoupper($level->name),
                featureOrCategoryString: null,
                file: $file,
                line: $line,
                func: self::fqMethodToFunc($fqMethod),
                messageWithContext: self::concatMessageAndContext($message, ((count($context) === 0) ? '' : json_encode($context, JSON_THROW_ON_ERROR))),
            );
        } else {
            (self::$formatAndWrite)(
                level: $level,
                file: $file,
                line: $line,
                func: self::fqMethodToFunc($fqMethod),
                message: $message,
                context: $context,
            );
        }
    }

    public static function logThrowable(LogLevel $level, string $file, int $line, string $fqMethod, string $throwableDesc, Throwable $throwable): void
    {
        if (!BuildToolsLog::isLevelEnabled(LogLevel::critical)) {
            return;
        }

        if (self::$formatAndWrite !== null) {
            (self::$formatAndWrite)(
                level: $level,
                file: $file,
                line: $line,
                func: self::fqMethodToFunc($fqMethod),
                message: '',
                context: [$throwableDesc => $throwable],
            );
            return;
        }

        $getTraceEntryProp = function (array $traceEntry, string $propKey, string $defaultValue): string {
            if (!array_key_exists($propKey, $traceEntry)) {
                return $defaultValue;
            }
            $propVal = $traceEntry[$propKey];
            return is_scalar($propVal) ? strval($propVal) : $defaultValue;
        };
        $stackTrace = [];
        foreach ($throwable->getTrace() as $traceEntry) {
            $stackTraceLine = $getTraceEntryProp($traceEntry, 'file', '<FILE>') . ':' . $getTraceEntryProp($traceEntry, 'line', '<LINE>');
            $stackTraceLine .= ' (' . $getTraceEntryProp($traceEntry, 'class', '<CLASS>') . '::' . $getTraceEntryProp($traceEntry, 'function', '<FUNC>') . ')';
            $stackTrace[] = $stackTraceLine;
        }
        self::withLevel($level, $file, $line, $fqMethod, '', [$throwableDesc => ['message' => $throwable->getMessage(), 'stack trace' => $stackTrace]]);
    }

    private static function concatWithSeparator(string $str1, string $separator, string $str2): string
    {
        return $str1 . ((($str1 === '') || ($str2 === '')) ? '' : $separator) . $str2;
    }

    public static function concatMessageAndContext(string $message, string $contextAsString): string
    {
        return self::concatWithSeparator($message, ' | ', $contextAsString);
    }

    public static function formatStatement(
        string $prefix,
        string $levelString,
        ?string $featureOrCategoryString,
        string $file,
        int $line,
        string $func,
        string $messageWithContext,
    ): string {
        $result = $prefix;
        $appendToResult = function (string $part, bool $surroundWithDelimiters = true) use (&$result): void {
            $result = self::concatWithSeparator($result, ' ', $surroundWithDelimiters ? "[$part]" : $part);
        };

        if (is_int($pid = getmypid())) {
            $appendToResult("PID: $pid");
        }

        $appendToResult((new DateTime())->format('Y-m-d H:i:s.v P'), surroundWithDelimiters: false);

        $appendToResult($levelString);

        if ($featureOrCategoryString !== null) {
            $appendToResult($featureOrCategoryString);
        }

        $appendToResult(basename($file) . ':' . $line);

        $appendToResult($func);

        $appendToResult($messageWithContext, surroundWithDelimiters: false);

        return $result;
    }

    public static function prodLogFeatureIntToString(int $prodLogFeatureIntVal): string
    {
        /** @var ?array<int, string> $valueToNameMap */
        static $valueToNameMap = null;
        if ($valueToNameMap === null) {
            $valueToNameMap = [];
            $logFeatureReflClass = new ReflectionClass(LogFeature::class);
            foreach ($logFeatureReflClass->getConstants() as $constName => $constValue) {
                $valueToNameMap[self::assertIsInt($constValue)] = $constName;
            }
        }

        if (array_key_exists($prodLogFeatureIntVal, $valueToNameMap)) {
            return $valueToNameMap[$prodLogFeatureIntVal];
        }
        return "UNKNOWN FEATURE $prodLogFeatureIntVal";
    }

    private const CLASS_METHOD_SEPARATOR = '::';

    private static function fqMethodToFunc(string $fqMethod): string
    {
        // __METHOD__ => MyClass::myMethod
        // result => myMethod

        /** @var ?int $separatorLen */
        static $separatorLen = null;
        if ($separatorLen === null) {
            $separatorLen = strlen(self::CLASS_METHOD_SEPARATOR);
        }
        $separatorPos = strrpos($fqMethod, self::CLASS_METHOD_SEPARATOR);
        return is_int($separatorPos) ? substr($fqMethod, $separatorPos + $separatorLen) : $fqMethod;
    }

    public static function defaultFormatAndWrite(string $levelString, ?string $featureOrCategoryString, string $file, int $line, string $func, string $messageWithContext): void
    {
        $formattedStatement = self::formatStatement(
            prefix: self::LOG_LINE_PREFIX,
            levelString: $levelString,
            featureOrCategoryString: $featureOrCategoryString,
            file: $file,
            line: $line,
            func: $func,
            messageWithContext: $messageWithContext,
        );
        self::writeLine($formattedStatement);
    }

    private static function ensureStdErrIsDefined(): bool
    {
        /** @var ?bool $isStderrDefined */
        static $isStderrDefined = null;

        if ($isStderrDefined === null) {
            if (defined('STDERR')) {
                $isStderrDefined = true;
            } else {
                define('STDERR', fopen('php://stderr', 'w'));
                $isStderrDefined = defined('STDERR');
            }
        }

        return $isStderrDefined;
    }

    public static function writeLine(string $text): void
    {
        if (self::ensureStdErrIsDefined()) {
            fwrite(STDERR, $text . PHP_EOL);
        }
    }

    public static function isLevelEnabled(LogLevel $level): bool
    {
        return self::isLevelIntEnabled($level->value);
    }

    public static function isLevelIntEnabled(int $levelIntVal): bool
    {
        return self::getMaxEnabledLevel()->value >= $levelIntVal;
    }

    public static function getMaxEnabledLevel(): LogLevel
    {
        self::assertNotNull(self::$maxEnabledLevel);
        return self::$maxEnabledLevel;
    }
}
