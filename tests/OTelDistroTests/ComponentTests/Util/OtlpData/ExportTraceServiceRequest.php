<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util\OtlpData;

use OTelDistroTests\Util\IterableUtil;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest as OTelProtoExportTraceServiceRequest;

/**
 * @see https://github.com/open-telemetry/opentelemetry-proto/blob/v1.8.0/opentelemetry/proto/collector/trace/v1/trace_service.proto#L34
 */
class ExportTraceServiceRequest
{
    /**
     * @param ResourceSpans[] $resourceSpans
     */
    public function __construct(
        public readonly array $resourceSpans,
    ) {
    }

    public static function deserializeFromOTelProto(OTelProtoExportTraceServiceRequest $source): self
    {
        return new self(
            resourceSpans: DeserializationUtil::deserializeArrayFromOTelProto($source->getResourceSpans(), ResourceSpans::deserializeFromOTelProto(...)),
        );
    }

    /**
     * @return iterable<Span>
     */
    public function spans(): iterable
    {
        $discardedTraceIds = $this->collectDiscardedTraceIds();
        foreach ($this->resourceSpans as $resourceSpans) {
            foreach ($resourceSpans->spans() as $span) {
                if (!isset($discardedTraceIds[$span->traceId])) {
                    yield $span;
                }
            }
        }
    }

    /**
     * @return array<string, true>
     */
    private function collectDiscardedTraceIds(): array
    {
        $discardedTraceIds = [];
        foreach ($this->resourceSpans as $resourceSpans) {
            foreach ($resourceSpans->scopeSpans as $scopeSpans) {
                foreach ($scopeSpans->discardedTraceIds as $traceId) {
                    $discardedTraceIds[$traceId] = true;
                }
            }
        }
        return $discardedTraceIds;
    }

    public function isEmptyAfterDeserialization(): bool
    {
        return IterableUtil::isEmpty($this->spans());
    }

    /**
     * @return iterable<OTelResource>
     */
    public function resources(): iterable
    {
        foreach ($this->resourceSpans as $resourceSpans) {
            if ($resourceSpans->resource !== null) {
                yield $resourceSpans->resource;
            }
        }
    }
}
