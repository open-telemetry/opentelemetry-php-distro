<?php

declare(strict_types=1);

namespace OTelDistroTests\Util;

use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\Logger;
use OTelDistroTests\Util\Log\LoggerFactory;
use Override;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class Clock implements ClockInterface
{
    private readonly Logger $logger;
    private ?float $lastSystemTime = null;
    private ?float $lastMonotonicTime = null;

    public function __construct(LoggerFactory $loggerFactory)
    {
        $this->logger = $loggerFactory->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)->addAllContext(compact('this'));
    }

    /**
     * @param-out ?float $last
     */
    private function checkAgainstUpdateLast(string $dbgSourceDesc, float $current, /* ref */ ?float &$last): float // @phpstan-ignore paramOut.unusedType
    {
        if ($last !== null) {
            if ($current < $last) {
                $this->logger->logDebug(__FUNCTION__)?->with(
                    __LINE__,
                    'Detected that clock has jumped backwards'
                    . ' - returning the later time (i.e., the time further into the future) instead',
                    [
                        'time source'         => $dbgSourceDesc,
                        'last as duration'    => TimeUtil::formatDurationInMicroseconds($last),
                        'current as duration' => TimeUtil::formatDurationInMicroseconds($current),
                        'current - last'      => TimeUtil::formatDurationInMicroseconds($current - $last),
                        'last as number'      => number_format($last),
                        'current as number'   => number_format($current),
                    ]
                );
                return $last;
            }
        }
        $last = $current;
        return $current;
    }

    /** @inheritDoc */
    #[Override]
    public function getSystemClockCurrentTime(): SystemTime
    {
        // Return value should be in microseconds
        // while microtime(as_float: true) returns current Unix timestamp in seconds with microseconds being the fractional part
        return new SystemTime($this->checkAgainstUpdateLast('microtime', round(TimeUtil::secondsToMicroseconds(microtime(as_float: true))), /* ref */ $this->lastSystemTime));
    }

    /** @inheritDoc */
    #[Override]
    public function getMonotonicClockCurrentTime(): MonotonicTime
    {
        $hrtimeRetVal = floatval(hrtime(as_number: true));
        return new MonotonicTime($this->checkAgainstUpdateLast('hrtime', round(TimeUtil::nanosecondsToMicroseconds($hrtimeRetVal)), /* ref */ $this->lastMonotonicTime));
    }
}
