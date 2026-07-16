<?php

/** @noinspection PhpInternalEntityUsedInspection */

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OTelDistroTests\ComponentTests\Util\OtlpData\ExportTraceServiceRequest;
use OTelDistroTests\ComponentTests\Util\OtlpData\OTelResource;
use OTelDistroTests\ComponentTests\Util\OtlpData\Span;
use OTelDistroTests\Util\Log\LoggableTrait;
use OpenTelemetry\Contrib\Otlp\ProtobufSerializer;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest as OTelProtoExportTraceServiceRequest;
use Override;

final class IntakeTraceDataRequest extends IntakeDataRequestDeserialized
{
    use LoggableTrait;

    private function __construct(
        IntakeDataRequestRaw $raw,
        private readonly ExportTraceServiceRequest $deserialized,
    ) {
        parent::__construct($raw);
    }

    public static function deserializeFromRaw(IntakeDataRequestRaw $raw): self
    {
        $serializer = ProtobufSerializer::getDefault();
        $otelProtoRequest = new OTelProtoExportTraceServiceRequest();
        $serializer->hydrate($otelProtoRequest, $raw->body);

        return new self($raw, ExportTraceServiceRequest::deserializeFromOTelProto($otelProtoRequest));
    }

    #[Override]
    public function isEmptyAfterDeserialization(): bool
    {
        return $this->deserialized->isEmptyAfterDeserialization();
    }

    /**
     * @return iterable<Span>
     */
    public function spans(): iterable
    {
        yield from $this->deserialized->spans();
    }

    /**
     * @return iterable<string>
     */
    public function directlyDiscardedSpanIds(): iterable
    {
        yield from $this->deserialized->directlyDiscardedSpanIds();
    }

    /**
     * @return iterable<OTelResource>
     */
    public function resources(): iterable
    {
        yield from $this->deserialized->resources();
    }
}
