<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Log;

use OpenTelemetry\Distro\Log\LogBackend;
use OpenTelemetry\Distro\Log\LogLevel;
use OpenTelemetry\DistroTools\Build\BuildToolsLogUtil;
use Override;

/**
 * @phpstan-import-type Context from SinkBase
 */
final class SinkForTests extends SinkBase
{
    public const LOG_LINE_PREFIX = '[OTel PHP Distro tests]';

    private const DEFAULT_SYSLOG_LEVEL = LOG_DEBUG;

    public function __construct(
        private readonly string $dbgProcessName
    ) {
    }

    /** @inheritDoc */
    #[Override]
    public function formatAndWrite(LogLevel $level, ?string $category, string $file, int $line, string $func, string $message, array $context): void
    {
        $formattedStatement = BuildToolsLogUtil::formatStatement(
            prefix: self::LOG_LINE_PREFIX . ' [' . $this->dbgProcessName . ']',
            level: $level,
            featureOrCategory: $category,
            file: $file,
            line: $line,
            func: $func,
            messageWithContext: LogBackend::concatMessageAndContext($message, $context === [] ? '' : LoggableToString::convert($context))
        );

        syslog(self::levelToSyslog($level->value), $formattedStatement);

        self::writeLineToStdErr($formattedStatement);
    }

    /**
     * @phpstan-param Context $context
     */
    public function formatAndWriteForLogBackend(LogLevel $level, null|int|string $featureOrCategory, string $file, int $line, string $func, string $message, array $context): void
    {
        $this->formatAndWrite(
            level: $level,
            category: is_int($featureOrCategory) ? BuildToolsLogUtil::prodLogFeatureIntToString($featureOrCategory) : $featureOrCategory,
            file: $file,
            line: $line,
            func: $func,
            message: $message,
            context: $context,
        );
    }

    public static function writeLineToStdErr(string $text): void
    {
        StdError::singletonInstance()->writeLine($text);
    }

    private static function levelToSyslog(int $levelInt): int
    {
        $levelEnum = LogLevel::tryFrom($levelInt);
        if ($levelEnum === null) {
            return self::DEFAULT_SYSLOG_LEVEL;
        }

        return match ($levelEnum) {
            LogLevel::off, LogLevel::critical => LOG_CRIT,
            LogLevel::error => LOG_ERR,
            LogLevel::warning => LOG_WARNING,
            LogLevel::info => LOG_INFO,
            default => self::DEFAULT_SYSLOG_LEVEL,
        };
    }
}
