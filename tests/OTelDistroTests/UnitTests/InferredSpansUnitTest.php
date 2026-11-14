<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests;

use OpenTelemetry\API\Trace\Span as ApiSpan;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Distro\InferredSpans\InferredSpans;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OTelDistroTests\ComponentTests\Util\InferredSpanExpectationsBuilder;
use OTelDistroTests\Util\TestCaseBase;
use ReflectionMethod;

final class InferredSpansUnitTest extends TestCaseBase
{
    private InMemoryExporter $exporter;
    private TracerProvider $tracerProvider;
    private ScopeInterface $globalsScope;

    public function setUp(): void
    {
        parent::setUp();

        $this->exporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
        $this->globalsScope = Sdk::builder()->setTracerProvider($this->tracerProvider)->buildAndRegisterGlobal();
    }

    public function tearDown(): void
    {
        $this->globalsScope->detach();
        $this->tracerProvider->shutdown();

        parent::tearDown();
    }

    public function testInferredAttributeName(): void
    {
        self::assertSame(InferredSpans::IS_INFERRED_ATTRIBUTE_NAME, InferredSpanExpectationsBuilder::IS_INFERRED_ATTRIBUTE_NAME); // @phpstan-ignore staticMethod.alreadyNarrowedType
    }

    /**
     * A sample taken inside a hooked function attaches inferred spans on top of the instrumentation's scope.
     * The instrumentation's post hook detaches the current scope and ends the span in it, so the inferred spans have to be
     * ended before it runs, or it ends an inferred span and its own span is never exported.
     */
    public function testInstrumentationSpanIsExportedWhenSampledInsideHookedFunction(): void
    {
        $inferredSpans = new InferredSpans(spanReductionEnabled: false, attachStackTrace: false, minSpanDuration: 0);
        $tracer = $this->tracerProvider->getTracer('test');

        $rootScope = self::startAndActivateSpan($tracer, 'root');

        self::sample($inferredSpans, [self::frame('main')]);

        InferredSpans::onHookedFunctionEnter();
        $instrumentationScope = self::startAndActivateSpan($tracer, 'instrumented');

        self::sample($inferredSpans, [self::frame('callee'), self::frame('instrumentedFunction'), self::frame('main')]);
        $tracer->spanBuilder('child of inferred')->startSpan()->end();

        InferredSpans::onHookedFunctionExit();
        self::instrumentationPostHook();

        $inferredSpans->shutdown();
        $rootScope->detach();
        ApiSpan::fromContext($rootScope->context())->end();
        unset($instrumentationScope);

        $spanNames = array_map(static fn (ImmutableSpan $span) => $span->getName(), $this->exportedSpans());
        self::assertContains('instrumented', $spanNames);
        self::assertContains('callee', $spanNames);
        self::assertContains('instrumentedFunction', $spanNames);
        self::assertContains('main', $spanNames);
        $this->assertNoOrphans();
    }

    /**
     * An inferred span that is shorter than the minimum duration is dropped, unless another span has it as parent.
     */
    public function testShortInferredSpanWithChildIsNotDropped(): void
    {
        $inferredSpans = new InferredSpans(spanReductionEnabled: true, attachStackTrace: false, minSpanDuration: 60_000);
        $tracer = $this->tracerProvider->getTracer('test');

        $rootScope = self::startAndActivateSpan($tracer, 'root');

        self::sample($inferredSpans, [self::frame('withChild'), self::frame('main')]);

        InferredSpans::onHookedFunctionEnter();
        self::startAndActivateSpan($tracer, 'instrumented');
        InferredSpans::onHookedFunctionExit();
        self::instrumentationPostHook();

        self::sample($inferredSpans, [self::frame('withoutChild'), self::frame('main')]);
        self::sample($inferredSpans, [self::frame('main')]);

        $inferredSpans->shutdown();
        $rootScope->detach();
        ApiSpan::fromContext($rootScope->context())->end();

        $spanNames = array_map(static fn (ImmutableSpan $span) => $span->getName(), $this->exportedSpans());
        self::assertContains('instrumented', $spanNames);
        self::assertContains('withChild', $spanNames);
        self::assertContains('main', $spanNames);
        self::assertNotContains('withoutChild', $spanNames);
        $this->assertNoOrphans();
    }

    /**
     * @param non-empty-string $name
     */
    private static function startAndActivateSpan(TracerInterface $tracer, string $name): ContextStorageScopeInterface
    {
        $span = $tracer->spanBuilder($name)->startSpan();
        return Context::storage()->attach($span->storeInContext(Context::getCurrent()));
    }

    /**
     * What instrumentations do in their post hook
     */
    private static function instrumentationPostHook(): void
    {
        $scope = Context::storage()->scope();
        self::assertNotNull($scope);
        $scope->detach();
        ApiSpan::fromContext($scope->context())->end();
    }

    /**
     * @param list<array{function: string, file: string, line: int}> $stackTrace
     */
    private static function sample(InferredSpans $inferredSpans, array $stackTrace): void
    {
        (new ReflectionMethod(InferredSpans::class, 'compareStackTraces'))->invoke($inferredSpans, $stackTrace, 0, false, null);
    }

    /**
     * @return array{function: string, file: string, line: int}
     */
    private static function frame(string $function): array
    {
        return ['function' => $function, 'file' => __FILE__, 'line' => crc32($function) % 1000];
    }

    /**
     * @return list<ImmutableSpan>
     */
    private function exportedSpans(): array
    {
        /** @var list<ImmutableSpan> $spans */
        $spans = $this->exporter->getSpans();
        return $spans;
    }

    private function assertNoOrphans(): void
    {
        $spans = $this->exportedSpans();
        $spanIds = array_map(static fn (ImmutableSpan $span) => $span->getSpanId(), $spans);
        foreach ($spans as $span) {
            if ($span->getParentContext()->isValid()) {
                self::assertContains($span->getParentSpanId(), $spanIds, 'Parent of span ' . $span->getName() . ' was not exported');
            }
        }
    }
}
