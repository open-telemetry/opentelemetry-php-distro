<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\Util\StaticClassTrait;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use PHPUnit\Framework\Assert;

/**
 * @phpstan-type MemoryUsage array{'desc': string, 'real': int, 'including_unused': int}
 */
final class MemoryUtil
{
    use StaticClassTrait;

    public static function formatSizeInBytes(int $sizeInBytes): string
    {
        /** @var list<string> $unitsSuffixes */
        static $unitsSuffixes = ['B', 'KB', 'MB', 'GB', 'TB'];
        /** @phpstan-var int $unitsStep */
        static $unitsStep = 1024;

        $absSizeInBytes = AssertEx::isInt(abs($sizeInBytes));
        $sign = $sizeInBytes >= 0 ? 1 : -1;

        $powOf2 = intval(AssertEx::isFloat(floor(($absSizeInBytes ? log($absSizeInBytes) : 0) / log($unitsStep))));
        $unitsIndex = min($powOf2, count($unitsSuffixes) - 1);
        $sizeInUnits = $absSizeInBytes / pow($unitsStep, $unitsIndex);

        $precision = $sizeInUnits >= 100 ? 0 : ($sizeInUnits >= 10 ? 1 : 2);
        return $sign * round($sizeInUnits, $precision) . ' ' . $unitsSuffixes[$unitsIndex];
    }

    /**
     * @return MemoryUsage
     */
    public static function getMemoryUsage(string $dbgCurrentStateDesc): array
    {
        return ['desc' => $dbgCurrentStateDesc, 'real' => memory_get_usage(/* real_usage: */ true), 'including_unused' => memory_get_usage()];
    }

    /**
     * @param array<string, array<int|string>> $nameToArray
     *
     * @return array<string, array<string>>
     */
    private static function formatArrayNameValuesSizeInBytes(array $nameToArray): array
    {
        Assert::assertCount(1, $nameToArray);
        $name = array_key_first($nameToArray);
        $formattedArray = array_map(fn($value) => is_int($value) ? self::formatSizeInBytes($value) : $value, AssertEx::isArray($nameToArray[$name]));
        /** @var array<string, string> $formattedArray */
        return [$name => $formattedArray];
    }

    /**
     * @param string $dbgCurrentStateDesc
     * @param ?MemoryUsage $prev
     *
     * @return MemoryUsage
     */
    public static function logMemoryUsage(string $dbgCurrentStateDesc, ?array $prev = null): array
    {
        $logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__);

        $current = self::getMemoryUsage($dbgCurrentStateDesc);
        $ctx = self::formatArrayNameValuesSizeInBytes(compact('current'));
        if ($prev !== null) {
            $delta = [];
            foreach ($current as $key => $value) {
                if ($key === 'desc') {
                    /** @var string $value */
                    $delta[$key] = $value . '-' . $prev[$key];
                } else {
                    /** @var int $value */
                    $delta[$key] = $value - $prev[$key];
                }
            }
            $ctx = array_merge(self::formatArrayNameValuesSizeInBytes(compact('delta')), $ctx);
        }

        $logger->logDebug(__FUNCTION__)?->with(__LINE__, 'Memory message', $ctx);

        return $current;
    }
}
