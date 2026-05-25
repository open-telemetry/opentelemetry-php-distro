<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OTelDistroTests\ComponentTests\Util\OtlpData\Span;
use OTelDistroTests\ComponentTests\Util\OtlpData\SpanKind;

final class SpanExpectations implements ExpectationsInterface
{
    use ExpectationsTrait;

    /**
     * @phpstan-param LeafExpectations<SpanKind> $kind
     * @phpstan-param LeafExpectations<string|null> $instrumentationScopeName
     */
    public function __construct(
        public readonly StringExpectations $name,
        public readonly LeafExpectations $kind,
        public readonly AttributesExpectations $attributes,
        public readonly LeafExpectations $instrumentationScopeName,
    ) {
    }

    public function assertMatches(Span $actual): void
    {
        $this->assertMatchesMixed($actual);
    }
}
