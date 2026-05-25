<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util\OtlpData;

use Opentelemetry\Proto\Trace\V1\Span as OTelProtoSpan;
use Opentelemetry\Proto\Trace\V1\SpanFlags as OTelProtoSpanFlags;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\UrlIncubatingAttributes;
use OTelDistroTests\ComponentTests\Util\IdGenerator;
use OTelDistroTests\ComponentTests\Util\TestInfraHttpServerProcessBase;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\FlagsUtil;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LoggableTrait;
use OTelDistroTests\Util\Log\LogStreamInterface;
use OTelDistroTests\Util\TextUtilForTests;
use PHPUnit\Framework\Assert;

final class Span implements LoggableInterface
{
    use LoggableTrait;

    /**
     * @param non-negative-int $droppedAttributesCount
     */
    public function __construct(
        public readonly Attributes $attributes,
        public readonly int $droppedAttributesCount,
        public readonly string $id,
        public readonly SpanKind $kind,
        public readonly string $name,
        public readonly ?string $parentId,
        public readonly string $traceId,
        public readonly int $flags,
        public readonly float $startTimeUnixNano,
        public readonly float $endTimeUnixNano,
        public readonly ?string $instrumentationScopeName,
    ) {
    }

    public static function deserializeFromOTelProto(OTelProtoSpan $source, ?string $instrumentationScopeName = null): self
    {
        return new self(
            attributes: Attributes::deserializeFromOTelProto($source->getAttributes()),
            droppedAttributesCount: AssertEx::isNonNegativeInt($source->getDroppedAttributesCount()),
            id: self::convertId($source->getSpanId()),
            kind: SpanKind::fromOTelProtoSpanKind($source->getKind()),
            name: $source->getName(),
            parentId: self::convertNullableId($source->getParentSpanId()),
            traceId: self::convertId($source->getTraceId()),
            flags: $source->getFlags(),
            startTimeUnixNano: self::convertTimeUnixNano($source->getStartTimeUnixNano()),
            endTimeUnixNano: self::convertTimeUnixNano($source->getEndTimeUnixNano()),
            instrumentationScopeName: $instrumentationScopeName,
        );
    }

    public static function reasonToDiscard(Span $span): ?string
    {
        /** @var string[] $attributesToCheckForTestsInfraUrlSubPath */
        static $attributesToCheckForTestsInfraUrlSubPath = [UrlAttributes::URL_PATH, UrlAttributes::URL_FULL, UrlIncubatingAttributes::URL_ORIGINAL];
        foreach ($attributesToCheckForTestsInfraUrlSubPath as $attributeName) {
            if (($reason = self::reasonToDiscardIfOptionalAttributeContainsString($span->attributes, $attributeName, TestInfraHttpServerProcessBase::BASE_URI_PATH)) !== null) {
                return $reason;
            }
        }

        return null;
    }

    private static function reasonToDiscardIfOptionalAttributeContainsString(Attributes $attributes, string $attributeName, string $subString): ?string
    {
        if (
            (($attributeValue = $attributes->tryToGetString($attributeName)) !== null)
            &&
            str_contains($attributeValue, $subString)
        ) {
            return 'Attribute (key: `' . $attributeName . '\', value: `' . $attributeValue . '\') contains `' . $subString . '\'';
        }

        return null;
    }

    private static function convertNullableId(string $binaryId): ?string
    {
        return $binaryId == '' ? null : self::convertId($binaryId);
    }

    private static function convertId(string $binaryId): string
    {
        AssertEx::notEmptyString($binaryId);

        /** @var int[] $idAsBytesSeq */
        $idAsBytesSeq = [];
        foreach (TextUtilForTests::iterateOverChars($binaryId) as $binaryIdCharAsInt) {
            $idAsBytesSeq[] = $binaryIdCharAsInt;
        }

        return IdGenerator::convertBinaryIdToString($idAsBytesSeq);
    }

    private static function convertTimeUnixNano(string|int $protoVal): float
    {
        if (is_int($protoVal)) {
            return floatval($protoVal);
        }

        Assert::assertNotFalse(filter_var($protoVal, FILTER_VALIDATE_INT));
        return floatval($protoVal);
    }

    public function hasRemoteParent(): ?bool
    {
        if (($this->flags & OTelProtoSpanFlags::SPAN_FLAGS_CONTEXT_HAS_IS_REMOTE_MASK) === 0) {
            return null;
        }
        return ($this->flags & OTelProtoSpanFlags::SPAN_FLAGS_CONTEXT_IS_REMOTE_MASK) !== 0;
    }

    private const SPAN_FLAGS_MASKS_TO_NAME = [
        OTelProtoSpanFlags::SPAN_FLAGS_CONTEXT_HAS_IS_REMOTE_MASK => 'HAS_IS_REMOTE',
        OTelProtoSpanFlags::SPAN_FLAGS_CONTEXT_IS_REMOTE_MASK     => 'IS_REMOTE',
    ];

    public function toLog(LogStreamInterface $stream): void
    {
        $flagsToLog = strval($this->flags);
        $flagsHumanReadable = IterableUtil::convertToString(FlagsUtil::extractBitNames($this->flags, self::SPAN_FLAGS_MASKS_TO_NAME), separator: ' | ');
        if ($flagsHumanReadable !== '') {
            $flagsToLog .= ' (' . $flagsHumanReadable . ')';
        }
        $customToLog = ['flags' => $flagsToLog];
        $this->toLogLoggableTraitImpl($stream, $customToLog);
    }
}
