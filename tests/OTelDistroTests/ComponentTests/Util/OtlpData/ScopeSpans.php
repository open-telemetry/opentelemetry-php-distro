<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util\OtlpData;

use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use Opentelemetry\Proto\Trace\V1\ScopeSpans as OTelProtoScopeSpans;
use Opentelemetry\Proto\Trace\V1\Span as OTelProtoSpan;

/**
 * @see https://github.com/open-telemetry/opentelemetry-proto/blob/v1.8.0/opentelemetry/proto/trace/v1/trace.proto#L68
 */
class ScopeSpans
{
    /**
     * @param Span[]   $spans
     * @param string[] $discardedSpanIds span IDs of spans that were directly discarded from this scope
     */
    public function __construct(
        public readonly ?InstrumentationScope $scope,
        public readonly array $spans,
        public readonly string $schemaUrl,
        public readonly array $discardedSpanIds = [],
    ) {
    }

    public static function deserializeFromOTelProto(OTelProtoScopeSpans $source): self
    {
        $scope = DeserializationUtil::deserializeNullableFromOTelProto($source->getScope(), InstrumentationScope::deserializeFromOTelProto(...));
        $scopeName = $scope?->name;

        $spans = [];
        /** @var array<string, true> $discardedSpanIds */
        $discardedSpanIds = [];
        /** @var OTelProtoSpan $protoSpan */
        foreach ($source->getSpans() as $protoSpan) {
            $span = self::deserializeSpanFromOTelProto($protoSpan, $scopeName, /* ref */ $discardedSpanIds);
            if ($span !== null) {
                $spans[] = $span;
            }
        }

        return new self(
            scope: $scope,
            spans: $spans,
            schemaUrl: $source->getSchemaUrl(),
            discardedSpanIds: array_keys($discardedSpanIds),
        );
    }

    /**
     * @param array<string, true> $discardedSpanIds
     */
    private static function deserializeSpanFromOTelProto(OTelProtoSpan $source, ?string $scopeName, array &$discardedSpanIds): ?Span
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);
        $dbgCtx->add(compact('source'));

        $span = Span::deserializeFromOTelProto($source, $scopeName);
        if (($reason = Span::reasonToDiscard($span)) !== null) {
            AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)->addAllContext(compact('source'))
                ->logDebug(__FUNCTION__)?->with(__LINE__, 'Span discarded', compact('reason', 'span'));
            $discardedSpanIds[$span->id] = true;
            return null;
        }

        return $span;
    }
}
