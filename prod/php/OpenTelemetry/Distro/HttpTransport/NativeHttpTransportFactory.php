<?php

declare(strict_types=1);

namespace OpenTelemetry\Distro\HttpTransport;

use OpenTelemetry\Distro\PhpPartVersion;
use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;

class NativeHttpTransportFactory implements TransportFactoryInterface
{
    public function create(
        string $endpoint,
        string $contentType,
        array $headers = [],
        mixed $compression = null,
        float $timeout = 10.,
        int $retryDelay = 100,
        int $maxRetries = 3,
        ?string $cacert = null,
        ?string $cert = null,
        ?string $key = null
    ): NativeHttpTransport {
        $headers['User-Agent'] = "otlp-http-php-distro/" . PhpPartVersion::VALUE;
        return new NativeHttpTransport($endpoint, $contentType, $headers, $compression, $timeout, $retryDelay, $maxRetries, $cacert, $cert, $key);
    }
}
