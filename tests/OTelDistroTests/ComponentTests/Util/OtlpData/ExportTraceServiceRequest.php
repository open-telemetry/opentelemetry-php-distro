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
        // Collect span IDs of directly discarded spans (those with infra URL attributes).
        // Then expand transitively: spans whose parent was discarded are also discarded.
        // This correctly handles inferred child spans of infra requests (different trace from
        // real test spans) without affecting spans that merely share a trace with a discarded span.
        $discardedSpanIds = $this->collectDiscardedSpanIds();
        do {
            $changed = false;
            foreach ($this->resourceSpans as $resourceSpans) {
                foreach ($resourceSpans->spans() as $span) {
                    if (!isset($discardedSpanIds[$span->id]) && $span->parentId !== null && isset($discardedSpanIds[$span->parentId])) {
                        $discardedSpanIds[$span->id] = true;
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        foreach ($this->resourceSpans as $resourceSpans) {
            foreach ($resourceSpans->spans() as $span) {
                if (!isset($discardedSpanIds[$span->id])) {
                    yield $span;
                }
            }
        }
    }

    /**
     * @return array<string, true>
     */
    private function collectDiscardedSpanIds(): array
    {
        $discardedSpanIds = [];
        foreach ($this->resourceSpans as $resourceSpans) {
            foreach ($resourceSpans->scopeSpans as $scopeSpans) {
                foreach ($scopeSpans->discardedSpanIds as $spanId) {
                    $discardedSpanIds[$spanId] = true;
                }
            }
        }
        return $discardedSpanIds;
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
