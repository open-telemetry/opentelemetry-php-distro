<?php

declare(strict_types=1);

namespace OTelDistroTests\Util;

use OpenTelemetry\Distro\Util\StaticClassTrait;
use OTelDistroTests\Util\Log\AdhocLoggableObject;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\LoggableStackTrace;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\Log\PropertyLogPriority;
use OTelDistroTests\Util\Log\SinkForTests as LogSinkForTests;
use Throwable;

final class ExceptionUtil
{
    use StaticClassTrait;

    /**
     * @param array<string, mixed> $context
     * @param ?non-negative-int    $numberOfStackFramesToSkip PHP_INT_MAX means no stack trace
     */
    public static function buildMessage(string $messagePrefix, array $context = [], ?int $numberOfStackFramesToSkip = null): string
    {
        $messageSuffixObj = new AdhocLoggableObject($context);
        if ($numberOfStackFramesToSkip !== null) {
            $stacktrace = LoggableStackTrace::buildForCurrent($numberOfStackFramesToSkip + 1);
            $messageSuffixObj->addProperties([LoggableStackTrace::STACK_TRACE_KEY => $stacktrace], PropertyLogPriority::MUST_BE_INCLUDED);
        }
        $messageSuffix = LoggableToString::convert($messageSuffixObj, prettyPrint: true);
        return $messagePrefix . ($messageSuffix === '' ? '' : ('. ' . $messageSuffix));
    }

    /**
     * @template TReturnValue
     *
     * @param callable(): TReturnValue $callableToRun
     *
     * @return TReturnValue
     *
     * @noinspection PhpDocMissingThrowsInspection
     */
    public static function runCatchWriteToStdErrRethrow(callable $callableToRun): mixed
    {
        try {
            return $callableToRun();
        } catch (Throwable $throwable) {
            LogSinkForTests::writeLineToStdErr('[CRITICAL] Throwable escaped: ' . $throwable);
            throw $throwable;
        }
    }

    /**
     * @template TReturnValue
     *
     * @param callable(): TReturnValue $callableToRun
     *
     * @return TReturnValue
     *
     * @noinspection PhpDocMissingThrowsInspection
     */
    public static function runCatchLogRethrow(callable $callableToRun): mixed
    {
        try {
            return $callableToRun();
        } catch (Throwable $throwable) {
            AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)
                ->logCritical(__FUNCTION__)?->withThrowable(__LINE__, 'Throwable escaped', $throwable);
            throw $throwable;
        }
    }
}
