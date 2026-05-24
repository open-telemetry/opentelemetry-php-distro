<?php

declare(strict_types=1);

namespace OpenTelemetry\Distro\Util;

use RuntimeException;
use Throwable;

/**
 * @phpstan-import-type Context from GetContextInterface
 */
class DistroRuntimeException extends RuntimeException implements GetContextInterface
{
    /**
     * @phpstan-param Context $context
     */
    public function __construct(
        string $message = '',
        private readonly array $context = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
