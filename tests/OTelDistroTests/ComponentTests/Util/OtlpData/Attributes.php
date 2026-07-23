<?php

/**
 * @noinspection PhpDeprecationInspection
 * Google\Protobuf\Internal\RepeatedField is deprecated, and Google\Protobuf\RepeatedField is used instead.
 */

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util\OtlpData;

use Countable;
use OpenTelemetry\Distro\Util\ArrayUtil;
use OTelDistroTests\Util\ArrayReadInterface;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\Log\LogStreamInterface;
use OTelDistroTests\Util\TextUtilForTests;
use Google\Protobuf\RepeatedField as ProtobufRepeatedField;
use Opentelemetry\Proto\Common\V1\KeyValue as OTelProtoKeyValue;
use Override;
use PHPUnit\Framework\Assert;

/**
 * @phpstan-type AttributeValue array<int>|array<mixed>|bool|float|int|null|string
 *
 * @implements ArrayReadInterface<string, AttributeValue>
 */
final class Attributes implements ArrayReadInterface, Countable, LoggableInterface
{
    /**
     * @param array<string, AttributeValue> $keyToValueMap
     */
    public function __construct(
        private readonly array $keyToValueMap
    ) {
    }

    /**
     * @param ProtobufRepeatedField<mixed> $source
     */
    public static function deserializeFromOTelProto(ProtobufRepeatedField $source): self
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $keyToValueMap = [];
        foreach ($source as $keyValue) {
            $dbgCtx->add(compact('keyValue'));
            Assert::assertInstanceOf(OTelProtoKeyValue::class, $keyValue);
            Assert::assertArrayNotHasKey($keyValue->getKey(), $keyToValueMap);
            $keyToValueMap[$keyValue->getKey()] = self::extractValue($keyValue);
        }

        return new self($keyToValueMap);
    }

    /**
     * @return AttributeValue
     */
    private static function extractValue(OTelProtoKeyValue $keyValue): array|bool|float|int|null|string
    {
        if (!$keyValue->hasValue()) {
            return null;
        }

        $anyValue = $keyValue->getValue();
        if ($anyValue === null) {
            return null;
        }

        if ($anyValue->hasArrayValue()) {
            $arrayValue = $anyValue->getArrayValue();
            if ($arrayValue === null) {
                return null;
            }
            $result = [];
            $arrayValues = $arrayValue->getValues();
            foreach ($arrayValues as $repeatedFieldSubValue) {
                $result[] = $repeatedFieldSubValue;
            }
            return $result;
        }

        if ($anyValue->hasBoolValue()) {
            return $anyValue->getBoolValue();
        }

        if ($anyValue->hasBytesValue()) {
            return IterableUtil::toList(TextUtilForTests::iterateOverChars($anyValue->getBytesValue()));
        }

        if ($anyValue->hasDoubleValue()) {
            return $anyValue->getDoubleValue();
        }

        if ($anyValue->hasIntValue()) {
            $value = $anyValue->getIntValue();
            if (is_int($value)) {
                return $value;
            }
            return AssertEx::stringIsInt($value);
        }

        if ($anyValue->hasKvlistValue()) {
            $kvListValue = $anyValue->getKvlistValue();
            if ($kvListValue === null) {
                return null;
            }
            $result = [];
            $kvListValues = $kvListValue->getValues();
            foreach ($kvListValues as $repeatedFieldSubKey => $repeatedFieldSubValue) {
                Assert::assertArrayNotHasKey($repeatedFieldSubKey, $result);
                $result[$repeatedFieldSubKey] = $repeatedFieldSubValue;
            }
            return $result;
        }

        if ($anyValue->hasStringValue()) {
            return $anyValue->getStringValue();
        }

        Assert::fail('Unknown value type; ' . LoggableToString::convert(compact('keyValue')));
    }

    #[Override]
    public function keyExists(int|string $key): bool
    {
        return array_key_exists($key, $this->keyToValueMap);
    }

    #[Override]
    public function getValue(int|string $key): mixed
    {
        Assert::assertIsString($key);
        Assert::assertTrue(ArrayUtil::getValueIfKeyExists($key, $this->keyToValueMap, /* out */ $attributeValue));
        return $attributeValue;
    }

    #[Override]
    public function count(): int
    {
        return count($this->keyToValueMap);
    }

    /**
     * @param AttributeValue  &$attributeValueOut
     *
     * @param-out AttributeValue $attributeValueOut
     */
    public function tryToGetValue(string $attributeName, array|bool|float|int|null|string &$attributeValueOut): bool
    {
        return ArrayUtil::getValueIfKeyExists($attributeName, $this->keyToValueMap, /* out */ $attributeValueOut); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    public function tryToGetBool(string $attributeName): ?bool
    {
        if (!$this->tryToGetValue($attributeName, /* out */ $attributeValue)) {
            return null;
        }
        return AssertEx::isBool($attributeValue);
    }

    public function tryToGetFloat(string $attributeName): ?float
    {
        if (!$this->tryToGetValue($attributeName, /* out */ $attributeValue)) {
            return null;
        }
        return AssertEx::isFloat($attributeValue);
    }

    public function tryToGetInt(string $attributeName): ?int
    {
        if (!$this->tryToGetValue($attributeName, /* out */ $attributeValue)) {
            return null;
        }
        return AssertEx::isInt($attributeValue);
    }

    public function tryToGetString(string $attributeName): ?string
    {
        if (!ArrayUtil::getValueIfKeyExists($attributeName, $this->keyToValueMap, /* out */ $attributeValue)) {
            return null;
        }
        return AssertEx::isString($attributeValue);
    }

    public function getBool(string $attributeName): bool
    {
        return AssertEx::notNull($this->tryToGetBool($attributeName));
    }

    /** @noinspection PhpUnused */
    public function getFloat(string $attributeName): float
    {
        return AssertEx::notNull($this->tryToGetFloat($attributeName));
    }

    public function getInt(string $attributeName): int
    {
        return AssertEx::notNull($this->tryToGetInt($attributeName));
    }

    public function getString(string $attributeName): string
    {
        return AssertEx::notNull($this->tryToGetString($attributeName));
    }

    public function toLog(LogStreamInterface $stream): void
    {
        $stream->toLogAs($this->keyToValueMap);
    }
}
