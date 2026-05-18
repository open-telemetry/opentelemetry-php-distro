<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use Closure;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LoggableTrait;

abstract class AppCodeHostHandle implements LoggableInterface
{
    use LoggableTrait;

    public function __construct(
        protected readonly TestCaseHandle $testCaseHandle,
        public readonly AppCodeHostParams $appCodeHostParams,
    ) {
    }

    /**
     * @param null|Closure(AppCodeRequestParams): void $setParamsFunc
     */
    abstract public function execAppCode(AppCodeTarget $appCodeTarget, ?Closure $setParamsFunc = null): ?int;

    protected function beforeAppCodeInvocation(AppCodeRequestParams $appCodeRequestParams): AppCodeInvocation
    {
        $timestampBefore = AmbientContextForTests::clock()->getSystemClockCurrentTime();
        return new AppCodeInvocation($appCodeRequestParams, $timestampBefore);
    }

    protected function afterAppCodeInvocation(AppCodeInvocation $appCodeInvocation): void
    {
        $appCodeInvocation->after();
        $this->testCaseHandle->addAppCodeInvocation($appCodeInvocation);
    }

    /**
     * @return string[]
     */
    protected static function propertiesExcludedFromLog(): array
    {
        return ['testCaseHandle'];
    }
}
