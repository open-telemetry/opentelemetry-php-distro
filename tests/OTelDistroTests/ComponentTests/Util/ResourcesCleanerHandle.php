<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OTelDistroTests\Util\ClassNameUtil;
use OTelDistroTests\Util\HttpMethods;
use OTelDistroTests\Util\HttpStatusCodes;
use PHPUnit\Framework\Assert;

final class ResourcesCleanerHandle extends HttpServerHandle
{
    private ResourcesCleanerClient $resourcesCleanerClient;

    public function __construct(HttpServerHandle $httpSpawnedProcessHandle)
    {
        parent::__construct(
            ClassNameUtil::fqToShort(ResourcesCleaner::class) /* <- dbgServerDesc */,
            $httpSpawnedProcessHandle->spawnedProcessOsId,
            $httpSpawnedProcessHandle->spawnedProcessInternalId,
            $httpSpawnedProcessHandle->ports
        );

        $this->resourcesCleanerClient = new ResourcesCleanerClient($this->spawnedProcessInternalId, $this->getMainPort());
    }

    public function getClient(): ResourcesCleanerClient
    {
        return $this->resourcesCleanerClient;
    }

    public function cleanTestScoped(): void
    {
        $response = $this->sendRequest(HttpMethods::POST, TestInfraHttpServerProcessBase::CLEAN_TEST_SCOPED_URI_PATH);
        Assert::assertSame(HttpStatusCodes::OK, $response->getStatusCode());
    }
}
