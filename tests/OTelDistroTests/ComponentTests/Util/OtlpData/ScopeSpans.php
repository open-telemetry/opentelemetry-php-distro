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
     * @param Span[] $spans
     */
    public function __construct(
        public readonly ?InstrumentationScope $scope,
        public readonly array $spans,
        public readonly string $schemaUrl,
    ) {
    }

    public static function deserializeFromOTelProto(OTelProtoScopeSpans $source): self
    {
        $scope = DeserializationUtil::deserializeNullableFromOTelProto($source->getScope(), InstrumentationScope::deserializeFromOTelProto(...));
        $scopeName = $scope?->name;

        return new self(
            scope: $scope,
            spans: DeserializationUtil::deserializeArrayFromOTelProto(
                $source->getSpans(),
                fn(OTelProtoSpan $protoSpan) => self::deserializeSpanFromOTelProto($protoSpan, $scopeName)
            ),
            schemaUrl: $source->getSchemaUrl(),
        );
    }

    private static function deserializeSpanFromOTelProto(OTelProtoSpan $source, ?string $scopeName): ?Span
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);
        $dbgCtx->add(compact('source'));

        $span = Span::deserializeFromOTelProto($source, $scopeName);
        if (($reason = Span::reasonToDiscard($span)) !== null) {
            AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)->addAllContext(compact('source'))
                ->logDebug(__FUNCTION__)?->with(__LINE__, 'Span discarded', compact('reason', 'span'));
            return null;
        }

        return $span;
    }
}
