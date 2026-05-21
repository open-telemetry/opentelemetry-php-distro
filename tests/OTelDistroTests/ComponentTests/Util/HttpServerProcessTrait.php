<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\HttpStatusCodes;
use OTelDistroTests\Util\JsonUtil;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

trait HttpServerProcessTrait
{
    protected static function verifySpawnedProcessInternalId(
        string $receivedSpawnedProcessInternalId
    ): ?ResponseInterface {
        $expectedSpawnedProcessInternalId = AmbientContextForTests::testConfig()->dataPerProcess()->thisSpawnedProcessInternalId;
        if ($expectedSpawnedProcessInternalId !== $receivedSpawnedProcessInternalId) {
            return self::buildErrorResponse(
                HttpStatusCodes::BAD_REQUEST,
                'Received server ID does not match the expected one.'
                . ' Expected: ' . $expectedSpawnedProcessInternalId
                . ', received: ' . $receivedSpawnedProcessInternalId
            );
        }

        return null;
    }

    protected static function buildErrorResponse(int $status, string $message): ResponseInterface
    {
        return new Response(
            $status,
            // headers:
            [
                'Content-Type' => 'application/json',
            ],
            // body:
            JsonUtil::encode(['message' => $message], /* prettyPrint: */ true)
        );
    }

    protected static function buildDefaultResponse(): ResponseInterface
    {
        return new Response();
    }

    protected static function buildResponseWithPid(): ResponseInterface
    {
        return Response::json([HttpServerHandle::PID_KEY => ProcessUtil::getCurrentPid()]);
    }
}
