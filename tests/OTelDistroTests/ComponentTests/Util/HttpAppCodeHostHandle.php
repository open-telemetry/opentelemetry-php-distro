<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use Closure;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\Logger;
use Override;

class HttpAppCodeHostHandle extends AppCodeHostHandle
{
    private readonly Logger $logger;

    public function __construct(
        TestCaseHandle $testCaseHandle,
        HttpAppCodeHostParams $appCodeHostParams,
        public readonly HttpServerHandle $httpServerHandle
    ) {
        parent::__construct($testCaseHandle, $appCodeHostParams);
        $this->logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)->addAllContext(compact('this'));
    }

    /** @inheritDoc */
    #[Override]
    public function execAppCode(AppCodeTarget $appCodeTarget, ?Closure $setParamsFunc = null): ?int
    {
        ($loggerProxy = $this->logger->ifDebugLevelEnabled(__LINE__, __FUNCTION__))
        && $loggerProxy->log('Sending HTTP request to app code ...', compact('appCodeTarget'));
        $this->sendHttpRequestToAppCode($appCodeTarget, $setParamsFunc);
        return null;
    }

    /**
     * @param null|Closure(HttpAppCodeRequestParams): void $setParamsFunc
     */
    private function sendHttpRequestToAppCode(AppCodeTarget $appCodeTarget, ?Closure $setParamsFunc = null): void
    {
        $requestParams = $this->buildRequestParams($appCodeTarget);
        if ($setParamsFunc !== null) {
            $setParamsFunc($requestParams);
        }

        $appCodeInvocation = $this->beforeAppCodeInvocation($requestParams);
        HttpClientUtilForTests::sendRequestToAppCode($requestParams);
        $this->afterAppCodeInvocation($appCodeInvocation);
    }

    public function buildRequestParams(AppCodeTarget $appCodeTarget): HttpAppCodeRequestParams
    {
        return new HttpAppCodeRequestParams($this->httpServerHandle, $appCodeTarget);
    }
}
