<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\Log;

use Closure;
use JsonException;
use OpenTelemetry\Distro\Util\GetContextInterface;
use RuntimeException;

/**
 * @phpstan-import-type Context from GetContextInterface
 *
 * @phpstan-type FormatAndWrite Closure(LogLevel $level, null|int|string $featureOrCategory, string $file, int $line, string $func, string $message, Context $context): void
 *
 * @phpstan-import-type StringList from SourceCodeFilePathProcessor
 */
final class LogBackend
{
    private static ?self $singletonInstance = null;

    public readonly LogLevel $maxEnabledLevel;
    private readonly SourceCodeFilePathProcessor $sourceCodeFilePathProcessor;

    /**
     * @phpstan-param StringList $sourceCodeRootDirs
     * @phpstan-param ?FormatAndWrite $formatAndWrite
     */
    public function __construct(
        int $maxEnabledLevel,
        array $sourceCodeRootDirs,
        private readonly ?Closure $formatAndWrite = null
    ) {
        $this->maxEnabledLevel = LogLevel::tryFrom($maxEnabledLevel) ?? self::getDefaultMaxEnabledLevel();
        $this->sourceCodeFilePathProcessor = new SourceCodeFilePathProcessor($sourceCodeRootDirs);

        $level = LogLevel::debug;
        if (self::isEnabledForLevel($level)) {
            $ctx = ['this' => $this->toLog()] + compact('maxEnabledLevel', 'sourceCodeRootDirs');
            self::write(file: __FILE__, line: __LINE__, func: __FUNCTION__, featureOrCategory: LogFeature::BOOTSTRAP, level: $level, message: 'Exiting...', context: $ctx);
        }
    }

    public static function initSingletonInstance(self $instance): void
    {
        if (self::$singletonInstance !== null) {
            throw new RuntimeException(self::class . ' singleton instance is not null');
        }
        self::$singletonInstance = $instance;
    }

    public static function resetSingletonInstance(self $instance): void
    {
        self::$singletonInstance = $instance;
    }

    public static function getSingletonInstance(): self
    {
        if (self::$singletonInstance === null) {
            throw new RuntimeException(self::class . ' singleton instance is null');
        }
        return self::$singletonInstance;
    }

    public static function getDefaultMaxEnabledLevel(): LogLevel
    {
        return LogLevel::info;
    }

    public function isEnabledForLevel(LogLevel $level): bool
    {
        return $level->value <= $this->maxEnabledLevel->value;
    }

    public static function concatWithSeparator(string $str1, string $separator, string $str2): string
    {
        return $str1 . ((($str1 === '') || ($str2 === '')) ? '' : $separator) . $str2;
    }

    /**
     * @phpstan-param Context $context
     */
    public static function contextToText(array $context = []): string
    {
        if (count($context) === 0) {
            return '';
        }

        try {
            $jsonEncodedCtx = json_encode($context, /* flags: */ JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $jsonEncodedCtx = 'Failed to JSON encode context: ' . $exception->getMessage();
        }

        return $jsonEncodedCtx;
    }

    public static function concatMessageAndContext(string $message, string $contextAsString): string
    {
        return self::concatWithSeparator($message, ' | ', $contextAsString);
    }

    /**
     * @phpstan-param Context $context
     */
    public function write(string $file, int $line, string $func, null|int|string $featureOrCategory, LogLevel $level, string $message, array $context, bool $isForced = false): void
    {
        $processedFilePath = $this->sourceCodeFilePathProcessor->processPath($file);
        if ($this->formatAndWrite === null) {
            assert(is_int($featureOrCategory));
            /**
             * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
             *
             * @noinspection PhpFullyQualifiedNameUsageInspection
             */
            \OpenTelemetry\Distro\log_feature(
                $isForced ? 1 : 0,
                $level->value,
                $featureOrCategory,
                $processedFilePath,
                $line,
                $func,
                self::concatMessageAndContext($message, $context === [] ? '' : self::contextToText($context)),
            );
        } else {
            ($this->formatAndWrite)(
                $level,
                $featureOrCategory,
                $processedFilePath,
                $line,
                $func,
                $message,
                $context
            );
        }
    }

    /**
     * @noinspection PhpUnused
     */
    public function possiblySecuritySensitive(mixed $value): mixed
    {
        return $this->isEnabledForLevel(LogLevel::trace) ? $value : 'REDACTED (POSSIBLY SECURITY SENSITIVE) DATA';
    }

    /**
     * @return Context
     */
    public function toLog(): array
    {
        return [
            'maxEnabledLevel' => $this->maxEnabledLevel->name,
            'sourceCodeFilePathProcessor' => $this->sourceCodeFilePathProcessor->toLog(),
            'formatAndWrite' => ($this->formatAndWrite === null ? '=' : '!') . '== null',
        ];
    }
}
