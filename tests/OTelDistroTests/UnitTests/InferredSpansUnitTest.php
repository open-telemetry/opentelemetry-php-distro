<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests;

use OpenTelemetry\API\Trace\Span as ApiSpan;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Distro\InferredSpans\InferredSpans;
use OpenTelemetry\Distro\PhpPartFacade;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OTelDistroTests\ComponentTests\Util\InferredSpanExpectationsBuilder;
use OTelDistroTests\Util\TestCaseBase;
use ReflectionMethod;
use ReflectionProperty;

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

    /**
     * @param list<SpanProcessorInterface> $extraSpanProcessors
     */
    private function replaceTracerProvider(array $extraSpanProcessors = [], ?SamplerInterface $sampler = null): void
    {
        $this->globalsScope->detach();
        $this->tracerProvider->shutdown();

        $this->exporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider([new SimpleSpanProcessor($this->exporter), ...$extraSpanProcessors], $sampler);
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
     * The extension notifies the inferred spans through the facade before the pre hooks and before the post hooks.
     */
    public function testFacadeNotificationsEndInferredSpansBeforePostHook(): void
    {
        $inferredSpans = new InferredSpans(spanReductionEnabled: false, attachStackTrace: false, minSpanDuration: 0);
        $tracer = $this->tracerProvider->getTracer('test');

        $rootScope = self::startAndActivateSpan($tracer, 'root');
        self::sample($inferredSpans, [self::frame('main')]);

        self::assertTrue(PhpPartFacade::inferredSpansHookedFunction(false));
        self::startAndActivateSpan($tracer, 'instrumented');
        self::sample($inferredSpans, [self::frame('callee'), self::frame('instrumentedFunction'), self::frame('main')]);

        self::assertTrue(PhpPartFacade::inferredSpansHookedFunction(true));
        self::instrumentationPostHook();

        $inferredSpans->shutdown();
        $rootScope->detach();
        ApiSpan::fromContext($rootScope->context())->end();

        $spanNames = array_map(static fn (ImmutableSpan $span) => $span->getName(), $this->exportedSpans());
        self::assertContains('instrumented', $spanNames);
        self::assertContains('callee', $spanNames);
        $this->assertNoOrphans();
    }

    /**
     * Nested hooked functions: each exit ends only the inferred spans started since its own enter.
     */
    public function testNestedHookedFunctionsEndOnlyTheirOwnInferredSpans(): void
    {
        $inferredSpans = new InferredSpans(spanReductionEnabled: false, attachStackTrace: false, minSpanDuration: 0);
        $tracer = $this->tracerProvider->getTracer('test');

        $rootScope = self::startAndActivateSpan($tracer, 'root');
        self::sample($inferredSpans, [self::frame('main')]);

        InferredSpans::onHookedFunctionEnter();
        self::startAndActivateSpan($tracer, 'outer');
        self::sample($inferredSpans, [self::frame('outerFunction'), self::frame('main')]);

        InferredSpans::onHookedFunctionEnter();
        self::startAndActivateSpan($tracer, 'inner');
        self::sample($inferredSpans, [self::frame('innerFunction'), self::frame('outerFunction'), self::frame('main')]);

        InferredSpans::onHookedFunctionExit();
        self::instrumentationPostHook(); // ends 'inner'
        InferredSpans::onHookedFunctionExit();
        self::instrumentationPostHook(); // ends 'outer'

        $inferredSpans->shutdown();
        $rootScope->detach();
        ApiSpan::fromContext($rootScope->context())->end();

        $spanNames = array_map(static fn (ImmutableSpan $span) => $span->getName(), $this->exportedSpans());
        self::assertContains('outer', $spanNames);
        self::assertContains('inner', $spanNames);
        $this->assertNoOrphans();
    }

    public function testExitWithoutEnterIsIgnored(): void
    {
        $inferredSpans = new InferredSpans(spanReductionEnabled: false, attachStackTrace: false, minSpanDuration: 0);
        $tracer = $this->tracerProvider->getTracer('test');
        $rootScope = self::startAndActivateSpan($tracer, 'root');

        // a stray exit must not unbalance the bookkeeping
        self::assertTrue(PhpPartFacade::inferredSpansHookedFunction(true));
        self::assertSame([], $this->exportedSpans());

        self::sample($inferredSpans, [self::frame('main')]);

        InferredSpans::onHookedFunctionEnter();
        self::startAndActivateSpan($tracer, 'instrumented');
        self::sample($inferredSpans, [self::frame('callee'), self::frame('instrumentedFunction'), self::frame('main')]);
        InferredSpans::onHookedFunctionExit();
        self::instrumentationPostHook();

        $inferredSpans->shutdown();
        $rootScope->detach();
        ApiSpan::fromContext($rootScope->context())->end();

        $spanNames = array_map(static fn (ImmutableSpan $span) => $span->getName(), $this->exportedSpans());
        self::assertContains('instrumented', $spanNames);
        self::assertContains('callee', $spanNames);
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
     * Only ids of inferred spans are kept, and only while they are in flight, so long-running processes don't accumulate them.
     */
    public function testChildrenBookkeepingDoesNotGrow(): void
    {
        $inferredSpans = new InferredSpans(spanReductionEnabled: true, attachStackTrace: false, minSpanDuration: 0);
        $tracer = $this->tracerProvider->getTracer('test');

        $rootScope = self::startAndActivateSpan($tracer, 'root');

        for ($i = 0; $i < 3; $i++) {
            self::sample($inferredSpans, [self::frame('work'), self::frame('main')]);

            // a hooked function entered while an instrumentation span (not an inferred one) is current
            $outerScope = self::startAndActivateSpan($tracer, 'outer instrumented');
            InferredSpans::onHookedFunctionEnter();
            self::startAndActivateSpan($tracer, 'inner instrumented');
            InferredSpans::onHookedFunctionExit();
            self::instrumentationPostHook();
            $outerScope->detach();
            ApiSpan::fromContext($outerScope->context())->end();

            self::sample($inferredSpans, []);
            self::assertSame([], self::getPrivateProperty($inferredSpans, 'spansWithChildren'));
            self::assertSame([], self::getPrivateProperty($inferredSpans, 'activeInferredSpanIds'));
        }

        $inferredSpans->shutdown();
        $rootScope->detach();
        ApiSpan::fromContext($rootScope->context())->end();
        $this->assertNoOrphans();
    }

    /**
     * The inferred spans of an unsampled trace are not recording, so there is nothing to end or export.
     * Their ids must not be kept, or they accumulate while an outer frame stays on the stack (long-running workers),
     * and their scopes must still be detached.
     */
    public function testNonRecordingInferredSpansAreNotTracked(): void
    {
        $this->replaceTracerProvider(sampler: new AlwaysOffSampler());
        $inferredSpans = new InferredSpans(spanReductionEnabled: true, attachStackTrace: false, minSpanDuration: 0);
        $contextBefore = Context::getCurrent();

        for ($i = 0; $i < 3; $i++) {
            self::sample($inferredSpans, [self::frame('job'), self::frame('worker loop')]);

            InferredSpans::onHookedFunctionEnter();
            InferredSpans::onHookedFunctionExit();

            self::sample($inferredSpans, [self::frame('worker loop')]);
            self::assertSame([], self::getPrivateProperty($inferredSpans, 'activeInferredSpanIds'));
            self::assertSame([], self::getPrivateProperty($inferredSpans, 'spansWithChildren'));
        }

        $inferredSpans->shutdown();
        self::assertSame($contextBefore, Context::getCurrent());
        self::assertSame([], $this->exportedSpans());
    }

    /**
     * Ending a span can run code (e.g. a span processor exporting a batch) during which a sample is taken.
     * A sample taken while the inferred spans of a hooked function are being ended must not change them.
     */
    public function testSampleTakenWhileEndingHookedFunctionFramesIsSkipped(): void
    {
        $samplingProcessor = new class implements SpanProcessorInterface {
            public ?InferredSpans $inferredSpans = null;
            public int $samplesTaken = 0;

            public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void
            {
            }

            public function onEnd(ReadableSpanInterface $span): void
            {
                // a sample taken while the inferred span 'callee' is being ended
                if ($this->inferredSpans !== null && $span->getName() === 'callee') {
                    // e.g. an exporter calling a hooked function, then a sample
                    InferredSpans::onHookedFunctionEnter();
                    InferredSpans::onHookedFunctionExit();
                    $this->samplesTaken++;
                    $this->inferredSpans->captureStackTrace(0, false);
                }
            }

            public function forceFlush(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }

            public function shutdown(?CancellationInterface $cancellation = null): bool
            {
                return true;
            }
        };
        $this->replaceTracerProvider([$samplingProcessor]);
        $inferredSpans = new InferredSpans(spanReductionEnabled: false, attachStackTrace: false, minSpanDuration: 0);
        $samplingProcessor->inferredSpans = $inferredSpans;
        $tracer = $this->tracerProvider->getTracer('test');

        $rootScope = self::startAndActivateSpan($tracer, 'root');

        self::sample($inferredSpans, [self::frame('main')]);

        InferredSpans::onHookedFunctionEnter();
        self::startAndActivateSpan($tracer, 'instrumented');

        self::sample($inferredSpans, [self::frame('callee'), self::frame('instrumentedFunction'), self::frame('main')]);

        InferredSpans::onHookedFunctionExit();
        self::instrumentationPostHook();

        self::assertSame(1, $samplingProcessor->samplesTaken);
        $samplingProcessor->inferredSpans = null;
        $inferredSpans->shutdown();
        $rootScope->detach();
        ApiSpan::fromContext($rootScope->context())->end();

        $spanNames = array_map(static fn (ImmutableSpan $span) => $span->getName(), $this->exportedSpans());
        self::assertContains('instrumented', $spanNames);
        self::assertContains('callee', $spanNames);
        self::assertContains('instrumentedFunction', $spanNames);
        self::assertContains('main', $spanNames);
        $this->assertNoOrphans();
    }

    private static function getPrivateProperty(InferredSpans $inferredSpans, string $name): mixed
    {
        return (new ReflectionProperty(InferredSpans::class, $name))->getValue($inferredSpans);
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
