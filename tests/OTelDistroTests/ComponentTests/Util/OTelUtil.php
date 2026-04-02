<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span as OTelApiSpan;
use OpenTelemetry\API\Trace\SpanInterface as OTelApiSpanInterface;
use OpenTelemetry\API\Trace\SpanKind as OTelSpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * @phpstan-type OTelAttributeScalarValue bool|int|float|string|null
 * @phpstan-type OTelAttributeValue OTelAttributeScalarValue|array<OTelAttributeScalarValue>
 * @phpstan-type OTelAttributesMapIterable iterable<non-empty-string, OTelAttributeValue>
 * @phpstan-type IntLimitedToOTelSpanKind OTelSpanKind::KIND_*
 */
final class OTelUtil
{
    /**
     * Use PHPDoc tags instead of PHP language native type hints to avoid runtime enforcement
     * because the runtime enforcement will fail when scoping is enabled
     *
     * @return TracerInterface
     *
     * @noinspection PhpMissingReturnTypeInspection
     */
    private static function getTracer()
    {
        return AppCodeContextUtil::adaptClassName(Globals::class)::tracerProvider()->getTracer(name: 'org.opentelemetry.php.distro.component-tests', version: Version::VERSION_1_27_0->value);
    }

    /**
     * @return class-string<Context>
     */
    private static function contextClass(): string
    {
        return AppCodeContextUtil::adaptClassName(Context::class);
    }

    /**
     * @return class-string<OTelApiSpan>
     */
    private static function apiSpanClass(): string
    {
        return AppCodeContextUtil::adaptClassName(OTelApiSpan::class);
    }

    /**
     * @phpstan-param TracerInterface $tracer
     * @phpstan-param non-empty-string $spanName
     * @phpstan-param IntLimitedToOTelSpanKind  $spanKind
     * @phpstan-param OTelAttributesMapIterable $attributes
     *
     * Use PHPDoc tags instead of PHP language native type hints to avoid runtime enforcement
     * because the runtime enforcement will fail when scoping is enabled
     *
     * @return OTelApiSpanInterface
     *
     * @noinspection PhpMissingReturnTypeInspection
     * @noinspection PhpReturnValueOfMethodIsNeverUsedInspection
     */
    private static function startSpan($tracer, string $spanName, int $spanKind = OTelSpanKind::KIND_INTERNAL, iterable $attributes = [])
    {
        $parentCtx = self::contextClass()::getCurrent();
        $newSpanBuilder = $tracer->spanBuilder($spanName)->setParent($parentCtx)->setSpanKind($spanKind)->setAttributes($attributes);
        $newSpan = $newSpanBuilder->startSpan();
        $newSpanCtx = $newSpan->storeInContext($parentCtx);
        self::contextClass()::storage()->attach($newSpanCtx);
        return $newSpan;
    }

    /**
     * @param OTelAttributesMapIterable $attributes
     *
     * @noinspection PhpSameParameterValueInspection
     */
    private static function endActiveSpan(?Throwable $throwable = null, ?string $errorStatus = null, iterable $attributes = []): void
    {
        $scope = self::contextClass()::storage()->scope();
        if ($scope === null) {
            return;
        }
        Assert::assertSame(0, $scope->detach());
        $span = self::apiSpanClass()::fromContext($scope->context());

        $span->setAttributes($attributes);

        if ($errorStatus !== null) {
            $span->setAttribute(TraceAttributes::EXCEPTION_MESSAGE, $errorStatus);
            $span->setStatus(StatusCode::STATUS_ERROR, $errorStatus);
        }

        if ($throwable) {
            $span->recordException($throwable);
            $span->setStatus(StatusCode::STATUS_ERROR, $throwable->getMessage());
        }

        $span->end();
    }

    /**
     * @phpstan-param non-empty-string          $spanName
     * @phpstan-param IntLimitedToOTelSpanKind  $spanKind
     * @phpstan-param OTelAttributesMapIterable $attributes
     */
    public static function startEndSpan(
        string $spanName,
        int $spanKind = OTelSpanKind::KIND_INTERNAL,
        iterable $attributes = [],
        ?Throwable $throwable = null,
        ?string $errorStatus = null
    ): void {
        self::startSpan(self::getTracer(), $spanName, $spanKind, $attributes);
        self::endActiveSpan($throwable, $errorStatus);
    }

    /**
     * @param iterable<string> $attributeKeys
     *
     * @return array<string, mixed>
     *
     * @noinspection PhpUnused
     */
    public static function dbgDescForSpan(OTelApiSpanInterface $span, iterable $attributeKeys = []): array
    {
        $result = ['class' => get_class($span), 'isRecording' => $span->isRecording()];
        if (method_exists($span, 'getName')) {
            $result['name'] = $span->getName();
        }
        if (method_exists($span, 'getAttribute')) {
            $attributes = [];
            foreach ($attributeKeys as $attributeKey) {
                $attributes[$attributeKey] = $span->getAttribute($attributeKey);
            }
            $result['attributes'] = $attributes;
        }
        return $result;
    }
}
