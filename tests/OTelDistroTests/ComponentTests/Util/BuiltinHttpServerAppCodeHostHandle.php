<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use Closure;
use OTelDistroTests\Util\ClassNameUtil;

final class BuiltinHttpServerAppCodeHostHandle extends HttpAppCodeHostHandle
{
    /**
     * @param Closure(HttpAppCodeHostParams): void $setParamsFunc
     * @param int[]                                $portsInUse
     */
    public function __construct(TestCaseHandle $testCaseHandle, Closure $setParamsFunc, ResourcesCleanerHandle $resourcesCleaner, array $portsInUse, string $dbgInstanceName)
    {
        $appCodeHostParams = new HttpAppCodeHostParams(dbgProcessNamePrefix: ClassNameUtil::fqToShort(BuiltinHttpServerAppCodeHost::class) . '_' . $dbgInstanceName);
        $setParamsFunc($appCodeHostParams);

        $httpServerHandle = BuiltinHttpServerAppCodeHostStarter::startBuiltinHttpServerAppCodeHost($appCodeHostParams, $resourcesCleaner, $portsInUse);
        $appCodeHostParams->serverId = $httpServerHandle->serverId;

        parent::__construct($testCaseHandle, $appCodeHostParams, $httpServerHandle);
    }
}
