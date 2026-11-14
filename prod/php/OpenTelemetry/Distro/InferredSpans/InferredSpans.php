<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro\InferredSpans;

use OpenTelemetry\Distro\OTelDistroScoperConfig;
use OpenTelemetry\Distro\Util\ArrayUtil;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Trace\Span as ApiSpan;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorageScopeInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Distro\Util\OTelUtil;
use OpenTelemetry\SDK\Trace\Span;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\SemConv\Version;
use OpenTelemetry\Context\ContextInterface;
use Throwable;
use WeakReference;

/**
 * @phpstan-type StackTraceFrameCallType '->'|'::'
 * @phpstan-type ExtendedStackTraceFrame array{function: string, line?: int, file?: string, class?: class-string, type?: StackTraceFrameCallType, span: WeakReference<SpanInterface>,
 *  context: WeakReference<ContextInterface>, scope: ContextStorageScopeInterface, stackTraceId: int}
 * @phpstan-type ExtendedStackTrace array<string|int, ExtendedStackTraceFrame>
 * @phpstan-type DebugBackTraceFrame array{function: string, line?: int, file?: string, class?: class-string, type?: StackTraceFrameCallType, args?: array<mixed>, object?: object}
 * @phpstan-type DebugBackTrace array<non-negative-int, DebugBackTraceFrame>
 */
class InferredSpans
{
    use LogsMessagesTrait;

    public const IS_INFERRED_ATTRIBUTE_NAME = 'is_inferred';

    private const METADATA_SPAN = 'span';
    private const METADATA_CONTEXT = 'context';
    private const METADATA_SCOPE = 'scope';
    private const METADATA_STACKTRACE_ID = 'stackTraceId';

    private const MILLIS_TO_NANOS = 1_000_000;
    private const FRAMES_TO_SKIP = 2;

    private TracerInterface $tracer;
    /** @var ExtendedStackTrace */
    private array $lastStackTrace;
    private int $stackTraceId = 0;

    private bool $shutdown;

    private static ?self $instance = null;

    /**
     * Value of $stackTraceId when each hooked function that is still running was entered
     *
     * @var list<int>
     */
    private array $hookedFunctionsEntryStackTraceIds = [];

    /**
     * IDs of spans that are the parent of at least one span that was (or will be) exported.
     * Such inferred spans must not be dropped, otherwise their children end up with a parent that is never exported.
     *
     * @var array<string, true>
     */
    private array $spansWithChildren = [];

    public function __construct(private readonly bool $spanReductionEnabled, private readonly bool $attachStackTrace, private readonly float $minSpanDuration)
    {
        $this->tracer = Globals::tracerProvider()->getTracer(
            'io.opentelemetry.php.distro.inferred-spans',
            null,
            Version::VERSION_1_30_0->url(),
        );

        self::logDebug('spanReductionEnabled ' . $spanReductionEnabled . ' attachStackTrace ' . $attachStackTrace . ' minSpanDuration ' . $minSpanDuration);

        $this->lastStackTrace = array();
        $this->shutdown = false;
        self::$instance = $this;
    }

    /**
     * Called before the pre hooks of a function hooked by an instrumentation
     */
    public static function onHookedFunctionEnter(): void
    {
        self::$instance?->hookedFunctionEnter();
    }

    /**
     * Called before the post hooks of a function hooked by an instrumentation
     */
    public static function onHookedFunctionExit(): void
    {
        self::$instance?->hookedFunctionExit();
    }

    private function hookedFunctionEnter(): void
    {
        $this->hookedFunctionsEntryStackTraceIds[] = $this->stackTraceId;

        if ($this->shutdown || empty($this->lastStackTrace)) {
            return;
        }

        // The instrumentation's pre hook usually starts a span with the current span as parent.
        // If the current span is an inferred span, it must be kept.
        $currentSpanContext = ApiSpan::getCurrent()->getContext();
        if ($currentSpanContext->isValid()) {
            $this->spansWithChildren[$currentSpanContext->getSpanId()] = true;
        }
    }

    private function hookedFunctionExit(): void
    {
        $entryStackTraceId = array_pop($this->hookedFunctionsEntryStackTraceIds);
        if ($entryStackTraceId === null || $this->shutdown) {
            return;
        }

        try {
            // Inferred spans started after the hooked function was entered are for frames that have returned by now
            // (the hooked function itself and the functions it called). Their scopes are attached on top of the scope
            // of the instrumentation's span. They have to be ended and detached now, before the instrumentation's post hook
            // detaches what it expects to be its own scope (Context::storage()->scope()) and ends the span in it.
            // Otherwise the post hook ends the inferred span instead, and the instrumentation's span is never ended
            // or exported, leaving its children with a parent that is missing from the trace.
            // This includes frames of callers of the hooked function that were first seen by a sample taken inside it:
            // their scopes were attached on top of the instrumentation's scope too. If they are still running,
            // the next sample starts them again.
            $framesToEnd = 0;
            foreach ($this->lastStackTrace as $frame) {
                if ($frame[self::METADATA_STACKTRACE_ID] <= $entryStackTraceId) {
                    break;
                }
                $framesToEnd++;
            }

            if ($framesToEnd > 0) {
                self::logDebug("Hooked function exit - ending " . $framesToEnd . " inferred span(s) started inside it");
                $this->endNewestFrames($framesToEnd, null);
            }
        } catch (Throwable $throwable) {
            self::logError($throwable->__toString());
        }
    }

    // $durationMs - duration between interrupt request and interrupt occurrence
    public function captureStackTrace(int $durationMs, bool $topFrameIsInternalFunction): void
    {
        self::logDebug("captureStackTrace topFrameInternal: $topFrameIsInternalFunction, duration: $durationMs ms shutdown: " . $this->shutdown);

        if ($this->shutdown) {
            return;
        }

        try {
            /* @var DebugBackTrace $stackTrace */
            $stackTrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

            array_splice($stackTrace, 0, InferredSpans::FRAMES_TO_SKIP); // skip PhpFacade/Inferred spans logic frames
            $apmFramesFilteredOutCount = $this->filterOutAPMFrames($stackTrace);

            $this->compareStackTraces($stackTrace, $durationMs, $topFrameIsInternalFunction, $apmFramesFilteredOutCount);
        } catch (Throwable $throwable) {
            self::logError($throwable->__toString());
        }
    }

    public function shutdown(): void
    {
        self::logDebug("shutdown");
        $this->shutdown = true;
        $fakeTrace = [];
        $this->compareStackTraces($fakeTrace, 0, false, 0);
    }

    /**
     * @param DebugBackTrace $stackTrace
    */
    private function compareStackTraces(array $stackTrace, int $durationMs, bool $topFrameIsInternalFunction, ?int $apmFramesFilteredOut): void
    {
        $this->stackTraceId++;

        $identicalFramesCount = $this->getHowManyStackFramesAreIdenticalFromStackBottom($stackTrace);
        self::logDebug("Same frames count: " . $identicalFramesCount); //, [$stackTrace, $this->lastStackTrace]);

        $lastStackTraceCount = count($this->lastStackTrace);
        $oldFramesCount = $lastStackTraceCount - $identicalFramesCount;

        // on previous stack trace - end all spans above identical frames
        // if last frame was internal function, so duration contains it's time, previous ones ended between sampling interval - they're shorter
        $this->endNewestFrames($oldFramesCount, $topFrameIsInternalFunction ? $durationMs : null);

        $stackTraceCount = count($stackTrace);
        if ($stackTraceCount == $identicalFramesCount) {
            // no frames to start
            return;
        }

        $first = true;

        // start spans for all frames below identical frames

        for ($index = $stackTraceCount - $identicalFramesCount - 1; $index >= 0; $index--) {
            if ($first && $apmFramesFilteredOut && !empty($this->lastStackTrace)) {
                self::logDebug("Going to start span in previous span context");
                $newFrame = $this->startFrameSpan($stackTrace[$index], $durationMs, $this->lastStackTrace[0][self::METADATA_CONTEXT]->get(), $this->stackTraceId);
            } else {
                $newFrame = $this->startFrameSpan($stackTrace[$index], $durationMs, null, $this->stackTraceId);
            }

            $first = false;

            if ($this->attachStackTrace) {
                $newFrame[self::METADATA_SPAN]->get()?->setAttribute(CodeAttributes::CODE_STACKTRACE, $this->getStackTrace($this->lastStackTrace));
            }

            if ($index == 0 && $topFrameIsInternalFunction) {
                /** @noinspection PhpRedundantOptionalArgumentInspection */
                $this->endFrameSpan($newFrame, false, null); // we don't need to save the newest internal frame, it ended
            } else {
                array_unshift($this->lastStackTrace, $newFrame); // push-copy frame in front of last stack trace for next interruption processing
            }
        }
    }

    /**
     * Ends the spans of the $framesToEnd newest frames of the last stack trace and removes those frames
     */
    private function endNewestFrames(int $framesToEnd, ?int $endedDurationMsAgo): void
    {
        $previousFrameStackTraceId = -1;
        $forceParentChangeFailed = false;

        for ($index = 0; $index < $framesToEnd; $index++) {
            $endEpochNanos = null;
            if ($endedDurationMsAgo !== null) {
                $endEpochNanos = $this->getStartTime($endedDurationMsAgo);
            }

            $dropSpan = false;
            if ($this->spanReductionEnabled) {
                $dropSpan = $this->shouldReduceFrame($index, $framesToEnd, $previousFrameStackTraceId, $forceParentChangeFailed);
            }

            $this->endFrameSpan($this->lastStackTrace[$index], $dropSpan, $endEpochNanos);

            unset($this->lastStackTrace[$index]); // remove ended frame
        }

        // reindex array
        $this->lastStackTrace = array_values($this->lastStackTrace);
    }

    private function hasChildren(SpanInterface $span): bool
    {
        return array_key_exists($span->getContext()->getSpanId(), $this->spansWithChildren);
    }

    private function getStartTime(int $durationMs): int
    {
        return Clock::getDefault()->now() - $durationMs * self::MILLIS_TO_NANOS;
    }

    /** @param-out DebugBackTrace $stackTrace
     *  @param DebugBackTrace $stackTrace
     *  @return ?int
     */
    private function filterOutAPMFrames(array &$stackTrace): ?int
    {
       // Filter out Distro and Otel SDK stack frames
        $cutIndex = null;
        for ($index = count($stackTrace) - 1; $index >= 0; $index--) {
            $frame = $stackTrace[$index];
            if (
                array_key_exists('class', $frame) &&
                (str_starts_with((string)$frame['class'], OTelDistroScoperConfig::PREFIX) || str_starts_with((string)$frame['class'], 'OpenTelemetry\\')) // TODO allow namespaces to be configured
            ) {
                $cutIndex = $index;
                break;
            }
        }

        if ($cutIndex !== null) {
            array_splice($stackTrace, 0, $cutIndex + 1);
        }
        return $cutIndex;
    }

    /** @param DebugBackTrace $stackTrace */
    private function getHowManyStackFramesAreIdenticalFromStackBottom(array $stackTrace): int
    {
        /**
         * Helper function to check if two frames are identical
         *
         * @phpstan-param DebugBackTraceFrame $frame1
         * @phpstan-param DebugBackTraceFrame $frame2
         */
        $isSameFrame = function (array $frame1, array $frame2): bool {
            $keysToCompare = ['class', 'function', 'file', 'line', 'type'];
            foreach ($keysToCompare as $key) {
                if (($frame1[$key] ?? null) !== ($frame2[$key] ?? null)) {
                    return false;
                }
            }
            return true;
        };

        $stackTraceCount = count($stackTrace);
        $lastStackTraceCount = count($this->lastStackTrace);

        $count = min($stackTraceCount, $lastStackTraceCount);

        for ($index = 1; $index <= $count; $index++) {
            $stFrame = &$stackTrace[$stackTraceCount - $index];
            $lastStFrame = &$this->lastStackTrace[$lastStackTraceCount - $index];

            if (!$isSameFrame($stFrame, $lastStFrame)) {
                return $index - 1;
            }
        }
        return $count;
    }

    private function shouldReduceFrame(int $index, int $oldFramesCount, int &$previousFrameStackTraceId, bool &$forceParentChangeFailed): bool
    {
        $frameStackTraceId = $this->lastStackTrace[$index][self::METADATA_STACKTRACE_ID];

        $dropSpan = $previousFrameStackTraceId == $frameStackTraceId; // if frame came from same stackTrace (interval) - we're dropping all spans above as they have same timing

        $previousFrameStackTraceId = $frameStackTraceId;

        if (!$dropSpan) { // if span should not be dropped, search for spans with same traceId and get parent from last one
            // find last span with same stackTraceId
            $lastSpanParent = null;
            for ($i = $index + 1; $i < $oldFramesCount; $i++) {
                if ($this->lastStackTrace[$i][self::METADATA_STACKTRACE_ID] != $frameStackTraceId) {
                    break;
                }

                $span = $this->lastStackTrace[$i][self::METADATA_SPAN]->get();
                if (!$span instanceof Span) {
                    break;
                }

                if ($this->hasChildren($span)) {
                    // this span is kept because other spans have it as parent - frames below it can't be skipped
                    $lastSpanParent = $span->getContext();
                    break;
                }

                $lastSpanParent = $span->getParentContext();
            }

            if ($lastSpanParent) {
                $span = $this->lastStackTrace[$index][self::METADATA_SPAN]->get();
                if (!$span instanceof Span) {
                    return false;
                }

                self::logDebug(
                    "Changing parent of span: '" . $span->getName() . "'",
                    ['new', $lastSpanParent, 'old', $span->getParentContext()]
                );

                /**
                 * Use fully qualified names for functions implemented by the extension to make sure scoper correctly detects them
                 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
                 */
                $forceParentChangeFailed = !\OpenTelemetry\Distro\InferredSpans\force_set_object_property_value($span, "parentSpanContext", $lastSpanParent);
            }
        }

        if ($forceParentChangeFailed && $dropSpan) {
            $dropSpan = false;
        }

        return $dropSpan;
    }

    /**
     * @phpstan-param ExtendedStackTraceFrame $frame
     */
    private function shouldDropTooShortSpan(array $frame, ?int $endEpochNanos = null): bool
    {
        if ($this->minSpanDuration <= 0) {
            return false;
        }

        $span = $frame[self::METADATA_SPAN]->get();
        if (!$span instanceof Span) {
            return false;
        }

        $duration = $endEpochNanos ? ($endEpochNanos - $span->getStartEpochNanos()) : $span->getDuration();
        if ($duration < $this->minSpanDuration * self::MILLIS_TO_NANOS) {
            self::logDebug('Span ' . $span->getName() . ' duration ' . intval($duration / self::MILLIS_TO_NANOS)
                . 'ms is too short to fit within the minimum span duration limit: ' . $this->minSpanDuration . 'ms');
            return true;
        }
        return false;
    }

    /**
     *  @param ExtendedStackTrace $stackTrace
     */
    private function getStackTrace(array $stackTrace): string
    {
        $id = 0;
        $str = "";
        foreach ($stackTrace as $frame) {
            if (array_key_exists('file', $frame)) {
                $file = $frame['file'] . '(' . ($frame['line'] ?? '') . ')';
            } else {
                $file = '[internal function]';
            }

            $str .= sprintf("#%d %s: %s%s%s()\n", $id, $file, $frame['class'] ?? '', $frame['type'] ?? '', $frame['function']);
            $id++;
        }
        $str .= sprintf("#%d {main}\n", $id);

        return $str;
    }

    /**
     * @phpstan-param DebugBackTraceFrame $frame
     */
    private static function setAttributeToFrameValue(array $frame, string $frameKey, SpanBuilderInterface $spanBuilder, string $attributeKey): void
    {
        if (ArrayUtil::getValueIfKeyExists($frameKey, $frame, /* out */ $frameValue) && (!empty($frameValue))) {
            $spanBuilder->setAttribute($attributeKey, $frameValue);
        }
    }

    /**
     * @phpstan-param DebugBackTraceFrame $frame
     */
    private static function getNonEmptyStringFrameValue(array $frame, string $frameKey): ?string
    {
        return (ArrayUtil::getValueIfKeyExists($frameKey, $frame, /* out */ $frameValue) && is_string($frameValue) && !empty($frameValue)) ? $frameValue : null;
    }

    /**
     * @phpstan-param DebugBackTraceFrame $frame
     *
     * @phpstan-return ExtendedStackTraceFrame
     */
    private function startFrameSpan(array $frame, int $durationMs, ?ContextInterface $parentContext, int $stackTraceId): array
    {
        $parent = $parentContext ?? Context::getCurrent();

        $spanName = (!empty($frame['class']) ? ($frame['class'] . '::') : '') . (!empty($frame['function']) ? $frame['function'] : '[unknown]');

        $builder = $this->tracer->spanBuilder($spanName)
            ->setParent($parent)
            ->setStartTimestamp($this->getStartTime($durationMs))
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttribute(self::IS_INFERRED_ATTRIBUTE_NAME, true);

        if (!empty($frameFunction = self::getNonEmptyStringFrameValue($frame, 'function'))) {
            $builder->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, OTelUtil::buildFqFunctionName(self::getNonEmptyStringFrameValue($frame, 'class'), $frameFunction));
        }
        self::setAttributeToFrameValue($frame, 'file', $builder, CodeAttributes::CODE_FILE_PATH);
        self::setAttributeToFrameValue($frame, 'line', $builder, CodeAttributes::CODE_LINE_NUMBER);

        $span = $builder->startSpan(); //OpenTelemetry\API\Trace\SpanInterface
        $context = $span->storeInContext($parent); //OpenTelemetry\Context\ContextInterface
        $scope = Context::storage()->attach($context); //OpenTelemetry\Context\ContextStorageScopeInterface

        $newFrame = $frame;
        $newFrame[self::METADATA_SPAN] = WeakReference::create($span);
        $newFrame[self::METADATA_CONTEXT] = WeakReference::create($context);
        $newFrame[self::METADATA_SCOPE] = $scope; // strong ref: keeps context/span alive even if detached externally
        $newFrame[self::METADATA_STACKTRACE_ID] = $stackTraceId;

        self::logDebug("Span started: " . $newFrame['function'] . " parentContext: " . ($parentContext ? "custom" : "default") . " stackTraceId: " . $stackTraceId);
        return $newFrame;
    }

    /**
     * @phpstan-param ExtendedStackTraceFrame $frame
     */
    private function endFrameSpan(array $frame, bool $dropSpan, ?int $endEpochNanos = null): void
    {
        if (!array_key_exists(self::METADATA_SPAN, $frame)) { // @phpstan-ignore function.alreadyNarrowedType
            self::logError("endFrameSpan missing metadata.", [$frame]);
            return;
        }

        if (!$dropSpan) {
            $dropSpan = $this->shouldDropTooShortSpan($frame, $endEpochNanos);
        }

        $span = $frame[self::METADATA_SPAN]->get();
        if (!$span instanceof Span) {
            self::logDebug("Span in frame is not instanceof Trace\Span", [$span, $frame]);
            return;
        }

        $scope = $frame[self::METADATA_SCOPE]; // strong ref — scope (and its context/span) kept alive by InferredSpans

        if ($dropSpan && $this->hasChildren($span)) {
            // dropping it would leave its children with a parent that is never exported
            self::logDebug("Span not dropped because it has children: " . $span->getName() . ' StackTraceId: ' . $frame[self::METADATA_STACKTRACE_ID]);
            $dropSpan = false;
        }
        unset($this->spansWithChildren[$span->getContext()->getSpanId()]);

        if ($dropSpan) {
            self::logDebug("Span dropped:   " . $span->getName() . ' StackTraceId: ' . $frame[self::METADATA_STACKTRACE_ID]);
            $scope->detach(); // safe: returns DETACHED flag if already detached by third party, no crash
            return;
        }

        // this span is going to be exported, so its parent must be exported too
        $this->spansWithChildren[$span->getParentContext()->getSpanId()] = true;

        $detachResult = $scope->detach();

        if ($detachResult & ScopeInterface::DETACHED) {
            // Scope was already detached by a third-party hook using Context::storage()->scope() anti-pattern
            // (e.g. opentelemetry-auto-curl POST hook). The strong ref above kept context and span alive,
            // so we can still end the span — child spans (e.g. user's billing.charge) will not be orphaned.
            $span->end($endEpochNanos);
            self::logDebug("Span finished (scope detached externally): " . $span->getName() . ' StackTraceId: ' . $frame[self::METADATA_STACKTRACE_ID]);
            return;
        }

        if ($scope->context() === Context::getCurrent()) {
            return;
        }

        $span->end($endEpochNanos);
        self::logDebug("Span finished:  " . $span->getName() . ' StackTraceId: ' . $frame[self::METADATA_STACKTRACE_ID]);
    }
}
