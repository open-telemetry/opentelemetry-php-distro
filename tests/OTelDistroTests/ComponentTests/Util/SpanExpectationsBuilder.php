<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\Util\OTelUtil;
use OTelDistroTests\ComponentTests\Util\OtlpData\SpanKind;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;

/**
 * @phpstan-import-type ArrayValue from AttributesExpectations as SpanAttributesExpectationsArrayValue
 */
class SpanExpectationsBuilder
{
    protected StringExpectations $name;

    /** @var LeafExpectations<SpanKind> */
    protected LeafExpectations $kind;

    protected AttributesExpectations $attributes;

    protected StackTraceExpectations $stackTrace;

    /** @var LeafExpectations<string|null> */
    protected LeafExpectations $instrumentationScopeName;

    public function __construct()
    {
        $this->name = StringExpectations::matchAny();
        $this->kind = LeafExpectations::matchAny(); // @phpstan-ignore assign.propertyType
        $this->attributes = AttributesExpectations::matchAny();
        $this->stackTrace = StackTraceExpectations::matchAny();
        $this->instrumentationScopeName = LeafExpectations::matchAny(); // @phpstan-ignore assign.propertyType
    }

    /**
     * @return $this
     */
    public function name(string $name): self
    {
        $this->name = StringExpectations::literal($name);
        return $this;
    }

    /**
     * @return $this
     *
     * @noinspection PhpUnused
     */
    public function nameRegEx(string $nameRegEx): self
    {
        $this->name = StringExpectations::regex($nameRegEx);
        return $this;
    }

    /**
     * @return $this
     */
    public function kind(SpanKind $kind): self
    {
        $this->kind = LeafExpectations::expectedValue($kind); // @phpstan-ignore assign.propertyType
        return $this;
    }

    /**
     * @return $this
     */
    public function attributes(AttributesExpectations $attributes): self
    {
        $this->attributes = $attributes;
        return $this;
    }

    /**
     * @return $this
     */
    public function nameAndCodeAttributesForFunction(string $fqFuncName): self
    {
        $this->name($fqFuncName);
        return $this->addAttribute(CodeAttributes::CODE_FUNCTION_NAME, $fqFuncName);
    }

    /**
     * @return $this
     */
    public function nameAndCodeAttributesForClassMethod(string $fqClassName, string $methodName): self
    {
        return $this->nameAndCodeAttributesForFunction(OTelUtil::buildFqFunctionName($fqClassName, $methodName));
    }

    /**
     * @phpstan-param SpanAttributesExpectationsArrayValue $value
     *
     * @return $this
     */
    public function addAttribute(string $key, array|bool|float|int|null|string|ExpectationsInterface $value): self
    {
        $this->attributes = $this->attributes->with($key, $value);
        return $this;
    }

    /**
     * @return $this
     */
    public function addNotAllowedAttribute(string $key): self
    {
        $this->attributes = $this->attributes->withNotAllowed($key);
        return $this;
    }

    /**
     * @return $this
     */
    public function serverAddress(string $value): self
    {
        return $this->addAttribute(ServerAttributes::SERVER_ADDRESS, $value);
    }

    /**
     * @return $this
     */
    public function serverPort(int $value): self
    {
        return $this->addAttribute(ServerAttributes::SERVER_PORT, $value);
    }

    /**
     * @return $this
     */
    public function stackTrace(StackTraceExpectations $stackTrace): self
    {
        return $this->addAttribute(CodeAttributes::CODE_STACKTRACE, $stackTrace);
    }

    /**
     * @return $this
     */
    public function instrumentationScopeName(string $name): self
    {
        $this->instrumentationScopeName = LeafExpectations::expectedValue($name); // @phpstan-ignore assign.propertyType
        return $this;
    }

    public function build(): SpanExpectations
    {
        return new SpanExpectations($this->name, $this->kind, $this->attributes, $this->instrumentationScopeName);
    }
}
