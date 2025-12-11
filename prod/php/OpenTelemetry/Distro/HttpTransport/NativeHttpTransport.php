<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\HttpTransport;

use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;

/**
 * @template-implements TransportInterface<string>
 */
final class NativeHttpTransport implements TransportInterface
{
    private string $endpoint;
    private string $contentType;

    /**
     * @param array<string,string|string[]> $headers
     *
     * @noinspection PhpUnusedParameterInspection
     *
     * Parameters $compression, $cacert, $cert and $key are unused so constructor.unusedParameter is mentioned 4 times below
     * @phpstan-ignore constructor.unusedParameter, constructor.unusedParameter, constructor.unusedParameter, constructor.unusedParameter
     */
    public function __construct(
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
    ) {
        $this->endpoint = $endpoint;
        $this->contentType = $contentType;

        // \OpenTelemetry\Distro\HttpTransport\initialize is provided by the extension
        initialize($endpoint, $contentType, $headers, $timeout, $retryDelay, $maxRetries);
    }

    public function contentType(): string
    {
        return $this->contentType;
    }

    /**
     * @return FutureInterface<null>
     */
    public function send(string $payload, ?CancellationInterface $cancellation = null): FutureInterface
    {
        // \OpenTelemetry\Distro\HttpTransport\enqueue is provided by the extension
        enqueue($this->endpoint, $payload);

        return new CompletedFuture(null);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return true;
    }
}
