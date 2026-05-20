<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\Util\StaticClassTrait;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\DebugContext;

final class TestMatrixRowUtil
{
    use StaticClassTrait;

    /**
     * @param ?list<string> $mandatoryParts
     *
     * @param-out list<string> $mandatoryParts
     * @param-out ?string $optionalPart
     *
     * @phpstan-assert list<string> $mandatoryParts
     */
    public static function split(string $row, /* out */ ?array &$mandatoryParts, /* out */ ?string &$optionalPart): void
    {
        /**
         * @see tools/test/component/generate_matrix.sh
         *
         * Expected format
         *
         *      php_version,package_type,test_app_host_kind_short_name,test_group[,<optional tail>]
         *      [0]         [1]          [2]                           [3]         [4]
         */

        /** @phpstan-var int $optionalTailPartIndex */
        static $optionalTailPartIndex = 4;

        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $parts = explode(',', $row, limit: $optionalTailPartIndex + 1);
        $dbgCtx->add(compact('parts'));
        AssertEx::countAtMost($optionalTailPartIndex + 1, $parts);

        $mandatoryParts = array_slice($parts, 0, $optionalTailPartIndex);
        $optionalPart = count($parts) > $optionalTailPartIndex ? $parts[$optionalTailPartIndex] : null;
    }
}
