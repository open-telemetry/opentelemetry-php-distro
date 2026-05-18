<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Log;

use OpenTelemetry\Distro\Log\LogLevel;
use OpenTelemetry\DistroTools\Build\BuildToolsLog;
use Override;

final class SinkForTests extends SinkBase
{
    public const LOG_LINE_PREFIX = '[OTel PHP Distro tests]';

    private const DEFAULT_SYSLOG_LEVEL = LOG_DEBUG;

    public function __construct(
        private readonly string $dbgProcessName
    ) {
    }

    #[Override]
    public function formatAndWrite(
        int $levelInt,
        string $levelString,
        string $category,
        string $srcCodeFile,
        int $srcCodeLine,
        string $srcCodeFunc,
        string $message,
        string $contextAsString,
    ): void {
        $formattedStatement = BuildToolsLog::formatStatement(
            prefix: self::LOG_LINE_PREFIX . ' [' . $this->dbgProcessName . ']',
            levelString: $levelString,
            featureOrCategoryString: $category,
            file: $srcCodeFile,
            line: $srcCodeLine,
            func: $srcCodeFunc,
            messageWithContext: BuildToolsLog::concatMessageAndContext($message, $contextAsString)
        );

        syslog(self::levelToSyslog($levelInt), $formattedStatement);

        self::writeLineToStdErr($formattedStatement);
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
