<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\DistroTools\Build;

use DateTime;
use OpenTelemetry\Distro\Log\LogBackend;
use OpenTelemetry\Distro\Log\LogFeature;
use OpenTelemetry\Distro\Log\LogLevel;
use ReflectionClass;

/**
 * @phpstan-import-type Context from LogBackend
 * @phpstan-import-type FormatAndWrite from LogBackend
 */
final class BuildToolsLogUtil
{
    use BuildToolsAssertTrait;

    private const LOG_LINE_PREFIX = '[OTel PHP Distro build tool]';
    public const DEFAULT_LEVEL = LogLevel::debug;

    public static function formatStatement(
        string $prefix,
        LogLevel $level,
        ?string $featureOrCategory,
        string $file,
        int $line,
        string $func,
        string $messageWithContext,
    ): string {
        $result = $prefix;
        $appendToResult = function (string $part, bool $surroundWithDelimiters = true) use (&$result): void {
            $result = LogBackend::concatWithSeparator($result, ' ', $surroundWithDelimiters ? "[$part]" : $part);
        };

        if (is_int($pid = getmypid())) {
            $appendToResult("PID: $pid");
        }

        $appendToResult((new DateTime())->format('Y-m-d H:i:s.v P'), surroundWithDelimiters: false);

        $appendToResult(strtoupper($level->name));

        if ($featureOrCategory !== null) {
            $appendToResult($featureOrCategory);
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

    /**
     * @phpstan-param Context $context
     */
    public static function formatAndWriteForLogBackend(LogLevel $level, null|int|string $featureOrCategory, string $file, int $line, string $func, string $message, array $context): void
    {
        self::writeLine(
            self::formatStatement(
                prefix: self::LOG_LINE_PREFIX,
                level: $level,
                featureOrCategory: is_int($featureOrCategory) ? self::prodLogFeatureIntToString($featureOrCategory) : $featureOrCategory,
                file: $file,
                line: $line,
                func: $func,
                messageWithContext: LogBackend::concatMessageAndContext($message, $context === [] ? '' : LogBackend::contextToText($context)),
            )
        );
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
}
