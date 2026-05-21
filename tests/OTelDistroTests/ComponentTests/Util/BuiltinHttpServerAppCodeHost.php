<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\GlobalUnderscoreServer;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\Logger;
use Override;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

final class BuiltinHttpServerAppCodeHost extends AppCodeHostBase
{
    use HttpServerProcessTrait;

    private readonly Logger $logger;

    public function __construct()
    {
        parent::__construct();

        $this->logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)->addAllContext(compact('this'));

        $this->logger->logDebug(__FUNCTION__)?->with(__LINE__, 'Received request', ['URI' => GlobalUnderscoreServer::requestUri(), 'method' => GlobalUnderscoreServer::requestMethod()]);
    }

    protected static function isStatusCheck(): bool
    {
        return GlobalUnderscoreServer::requestUri() === HttpServerHandle::STATUS_CHECK_URI_PATH;
    }

    #[Override]
    protected function shouldRegisterThisProcessWithResourcesCleaner(): bool
    {
        // We should register with ResourcesCleaner only on the status-check request
        return self::isStatusCheck();
    }

    #[Override]
    protected function processConfig(): void
    {
        Assert::assertCount(1, AmbientContextForTests::testConfig()->dataPerProcess()->thisServerPorts);

        parent::processConfig();

        AmbientContextForTests::reconfigure(new RequestHeadersRawSnapshotSource(fn(string $headerName) => GlobalUnderscoreServer::getRequestHeaderValue($headerName)));
    }

    #[Override]
    protected function runImpl(): void
    {
        $dataPerRequest = AmbientContextForTests::testConfig()->dataPerRequest();
        if (($response = self::verifySpawnedProcessInternalId($dataPerRequest->spawnedProcessInternalId)) !== null) {
            self::sendResponse($response);
            return;
        }
        if (self::isStatusCheck()) {
            self::sendResponse(self::buildResponseWithPid());
            return;
        }

        $this->callAppCode();
    }

    private static function sendResponse(ResponseInterface $response): void
    {
        $httpResponseStatusCode = $response->getStatusCode();

        AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)
            ->logDebug(__FUNCTION__)?->with(__LINE__, 'Sending response ...', compact('httpResponseStatusCode', 'response'));

        http_response_code($httpResponseStatusCode);
        echo $response->getBody();
    }
}
