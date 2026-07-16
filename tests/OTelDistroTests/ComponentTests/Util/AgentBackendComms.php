<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OTelDistroTests\ComponentTests\Util\OtlpData\Attributes;
use OTelDistroTests\ComponentTests\Util\OtlpData\OTelResource;
use OTelDistroTests\ComponentTests\Util\OtlpData\Span;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\IterableUtil;
use PHPUnit\Framework\Assert;

/**
 * @phpstan-import-type AttributeValue from Attributes
 */
final class AgentBackendComms
{
    /**
     * @param iterable<AgentBackendCommEvent> $commEvents
     * @param list<AgentBackendConnection> $connections
     */
    public function __construct(
        public readonly iterable $commEvents,
        public readonly array $connections,
    ) {
    }

    /**
     * @return array{'counts': array{'spans': int}}
     */
    public function dbgGetSummary(): array
    {
        return ['counts' => ['spans' => IterableUtil::count($this->spans())]];
    }

    /**
     * @return iterable<IntakeDataRequestDeserialized>
     */
    public function intakeDataRequests(): iterable
    {
        foreach ($this->connections as $connection) {
            yield from $connection->requests;
        }
    }

    /**
     * @return iterable<IntakeTraceDataRequest>
     */
    public function intakeTraceDataRequests(): iterable
    {
        foreach ($this->intakeDataRequests() as $request) {
            if ($request instanceof IntakeTraceDataRequest) {
                yield $request;
            }
        }
    }

    /**
     * @return iterable<Span>
     */
    public function spans(): iterable
    {
        // Build the discard set across ALL batches so that cross-batch parent→child
        // relationships are handled: a child span exported before its parent still gets
        // discarded once the parent is seen in a later batch.
        /** @var array<string, true> $discardedSpanIds */
        $discardedSpanIds = [];
        foreach ($this->intakeTraceDataRequests() as $request) {
            foreach ($request->directlyDiscardedSpanIds() as $spanId) {
                $discardedSpanIds[$spanId] = true;
            }
        }
        do {
            $changed = false;
            foreach ($this->intakeTraceDataRequests() as $request) {
                foreach ($request->spans() as $span) {
                    if (!isset($discardedSpanIds[$span->id]) && $span->parentId !== null && isset($discardedSpanIds[$span->parentId])) {
                        $discardedSpanIds[$span->id] = true;
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        foreach ($this->intakeTraceDataRequests() as $request) {
            foreach ($request->spans() as $span) {
                if (!isset($discardedSpanIds[$span->id])) {
                    yield $span;
                }
            }
        }
    }

    /**
     * @return iterable<OTelResource>
     */
    public function resources(): iterable
    {
        foreach ($this->intakeTraceDataRequests() as $intakeRequest) {
            yield from $intakeRequest->resources();
        }
    }

    /** @noinspection PhpUnused */
    public function singleSpan(): Span
    {
        return IterableUtil::singleValue($this->spans());
    }

    /**
     * @return Span[]
     */
    public function findSpansByName(string $name): array
    {
        $result = [];
        foreach ($this->spans() as $span) {
            if ($span->name === $name) {
                $result[] = $span;
            }
        }
        return $result;
    }

    /**
     * @return Span[]
     */
    public function findSpansById(string $id): array
    {
        $result = [];
        foreach ($this->spans() as $span) {
            if ($span->id === $id) {
                $result[] = $span;
            }
        }
        return $result;
    }

    public function findSpanById(string $id): ?Span
    {
        $spans = $this->findSpansById($id);
        if (ArrayUtilForTests::isEmpty($spans)) {
            return null;
        }
        return ArrayUtilForTests::getSingleValue($spans);
    }

    /**
     * @noinspection PhpUnused
     */
    public function singleSpanByName(string $name): Span
    {
        $spans = $this->findSpansByName($name);
        Assert::assertCount(1, $spans);
        return $spans[0];
    }

    /**
     * @return iterable<Span>
     *
     * @noinspection PhpUnused
     */
    public function findChildSpans(string $parentId): iterable
    {
        foreach ($this->spans() as $span) {
            if ($span->parentId === $parentId) {
                yield $span;
            }
        }
    }

    /**
     * @return iterable<Span>
     */
    public function findRootSpans(): iterable
    {
        foreach ($this->spans() as $span) {
            if ($span->parentId === null) {
                yield $span;
            }
        }
    }

    public function singleRootSpan(): Span
    {
        return IterableUtil::singleValue($this->findRootSpans());
    }

    public function singleChildSpan(string $parentId): Span
    {
        return IterableUtil::singleValue($this->findChildSpans($parentId));
    }

    /**
     * @return \Generator<int, Span>
     */
    public function findSpansByInstrumentationScope(string $scopeName): \Generator
    {
        foreach ($this->spans() as $span) {
            if ($span->instrumentationScopeName === $scopeName) {
                yield $span;
            }
        }
    }

    /**
     * @param non-empty-string   $attributeName
     * @phpstan-param AttributeValue $attributeValueToFind
     *
     * @return iterable<Span>
     */
    public function findSpansWithAttributeValue(string $attributeName, array|bool|float|int|null|string $attributeValueToFind): iterable
    {
        foreach ($this->spans() as $span) {
            if ($span->attributes->tryToGetValue($attributeName, /* out */ $actualAttributeValue) && $actualAttributeValue === $attributeValueToFind) {
                yield $span;
            }
        }
    }

    public function isSpanDescendantOf(Span $descendant, Span $ancestor): bool
    {
        $current = $descendant;
        while (true) {
            if ($current->id === $ancestor->id) {
                return true;
            }
            if ($current->parentId === null) {
                break;
            }
            /** @var Span $current */
            $current = AssertEx::notNull($this->findSpanById($current->parentId));
        }
        return false;
    }
}
