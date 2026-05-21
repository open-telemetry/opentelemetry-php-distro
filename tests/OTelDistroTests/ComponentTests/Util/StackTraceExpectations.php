<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\Util\ArrayUtil;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\StackTraceUtil;
use OTelDistroTests\Util\TextUtilForTests;
use PHPUnit\Framework\Assert;

/**
 * @phpstan-import-type DebugBacktraceResult from StackTraceUtil
 */
final class StackTraceExpectations implements ExpectationsInterface
{
    use ExpectationsTrait;

    /**
     * @param list<StackTraceFrameExpectations> $frames
     */
    public function __construct(
        public readonly array $frames,
        public readonly bool $allowToBePrefixOfActual,
    ) {
    }

    public static function matchAny(): self
    {
        /** @var ?self $cached */
        static $cached = null;
        return $cached ??= new self(frames: [], allowToBePrefixOfActual: true);
    }

    /**
     * @phpstan-param DebugBacktraceResult $debugBacktraceResult
     */
    public static function fromDebugBacktrace(array $debugBacktraceResult): self
    {
        /** @var list<StackTraceFrameExpectations> $framesExpectations */
        $framesExpectations = [];
        foreach ($debugBacktraceResult as $debugBacktraceFrame) {
            $frameExpectationsBuilder = new StackTraceFrameExpectationsBuilder();
            if (ArrayUtil::getValueIfKeyExists(StackTraceUtil::FILE_KEY, $debugBacktraceFrame, /* out */ $file)) {
                $frameExpectationsBuilder->file(AssertEx::isString($file));
            }
            if (ArrayUtil::getValueIfKeyExists(StackTraceUtil::LINE_KEY, $debugBacktraceFrame, /* out */ $line)) {
                $frameExpectationsBuilder->line(AssertEx::isPositiveInt($line));
            }
            $class = AssertEx::isNullableString(ArrayUtil::getValueIfKeyExistsElse(StackTraceUtil::CLASS_KEY, $debugBacktraceFrame, null));
            $methodKind = AssertEx::isNullableString(ArrayUtil::getValueIfKeyExistsElse(StackTraceUtil::METHOD_KIND_KEY, $debugBacktraceFrame, null));
            $func = AssertEx::isNullableString(ArrayUtil::getValueIfKeyExistsElse(StackTraceUtil::FUNCTION_KEY, $debugBacktraceFrame, null));
            $combinedFunc = ($class ?? '') . ($methodKind ?? '') . ($func ?? '');
            $frameExpectationsBuilder->function($combinedFunc);
            $framesExpectations[] = $frameExpectationsBuilder->build();
        }
        return new self($framesExpectations, allowToBePrefixOfActual: false);
    }

    public function assertMatchesConvertedToString(string $convertedToString): void
    {
        // #0 [internal function]: OTelDistroTests\ComponentTests\\InferredSpansComponentTest::appCodeForTestInferredSpans
        // #1 /app/AppCodeHostBase.php(112): call_user_func
        // #2 /app/CliScriptAppCodeHost.php(35): OTelDistroTests\ComponentTests\\Util\\AppCodeHostBase->callAppCode
        // #3 /app/AppCodeHostBase.php(83): OTelDistroTests\ComponentTests\\Util\\CliScriptAppCodeHost->runImpl
        // #4 /app/SpawnedProcessBase.php(107): OTelDistroTests\ComponentTests\\Util\\AppCodeHostBase::{closure:OTelDistroTests\ComponentTests\\Util\\AppCodeHostBase::run():68}
        // #5 /app/AppCodeHostBase.php(67): OTelDistroTests\ComponentTests\\Util\\SpawnedProcessBase::runSkeleton
        // #6 /app/runCliScriptAppCodeHost.php(28): OTelDistroTests\ComponentTests\\Util\\AppCodeHostBase::run
        // #7 {main}

        DebugContext::getCurrentScope(/* out */ $dbgCtx);
        $index = 0;
        $encounteredMain = false;
        $dbgCtx->pushSubScope();
        foreach (TextUtilForTests::iterateLines($convertedToString) as $textLine) {
            $dbgCtx->resetTopSubScope(compact('index', 'textLine'));

            if ($textLine === '') {
                continue;
            }

            // Line with "{main}" should be the last non-empty line
            Assert::assertFalse($encounteredMain);
            $expectedIndexPrefix = "#{$index} ";
            Assert::assertStringStartsWith($expectedIndexPrefix, $textLine);
            $frameConvertedToString = substr($textLine, strlen($expectedIndexPrefix));
            if ($frameConvertedToString === '{main}') {
                $encounteredMain = true;
                continue;
            }
            $frameExpectations = $index < count($this->frames) ? $this->frames[$index] : StackTraceFrameExpectations::matchAny();
            $frameExpectations->assertMatchesConvertedToString($frameConvertedToString);
            ++$index;
        }
        $dbgCtx->popSubScope();

        if ($this->allowToBePrefixOfActual) {
            Assert::assertGreaterThanOrEqual(count($this->frames), $index);
        } else {
            Assert::assertSame(count($this->frames), $index);
        }
    }

    public function assertMatchesMixed(mixed $actual): void
    {
        $this->assertMatchesConvertedToString(AssertEx::isString($actual));
    }
}
