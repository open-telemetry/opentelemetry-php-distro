<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use ArrayAccess;
use ArrayIterator;
use Ds\Set;
use IteratorAggregate;
use OpenTelemetry\Distro\Util\ArrayUtil;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\Log\LogStreamInterface;
use OTelDistroTests\Util\NumericUtilForTests;
use OTelDistroTests\Util\OsUtil;
use Override;
use PHPUnit\Framework\Assert;
use ReturnTypeWillChange;
use Traversable;

/**
 * @phpstan-import-type Pid from ProcessUtil
 * @phpstan-type PidToAdditionalDetails array<Pid, RunningProcessAdditionalDetails>
 * @phpstan-type PidToDbgDesc array<Pid, string>
 */
final class RunningProcessesInfo implements ArrayAccess, IteratorAggregate, LoggableInterface
{
    private const PID_PS_COLUMN_NAME = 'PID';
    private const PPID_PS_COLUMN_NAME = 'PPID';
    private const STATE_PS_COLUMN_NAME = 'STAT';
    private const COMMAND_PS_COLUMN_NAME = 'COMMAND';

    /**
     * @param PidToAdditionalDetails $pidToAdditionalDetails
     */
    public function __construct(
        public readonly array $pidToAdditionalDetails,
    ) {
    }

    /**
     * @param iterable<string> $outputLines
     */
    public static function parsePsCommandOutput(iterable $outputLines): self
    {
        /**
         * Example:
         *
         *          PID    PPID STAT COMMAND
         *       209277  209253 I    sh -c phpunit -c phpunit_component_tests.xml '--group' 'does_not_require_external_services'
         *       209278  209277 R+   php phpunit -c phpunit_component_tests.xml --group does_not_require_external_services
         *       209280       1 R+   php runResourcesCleaner.php 2>&1 | tee ResourcesCleaner.log
         */

        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        /** @var list<string> $expectedFirstLineParts */
        static $expectedFirstLineParts = [self::PID_PS_COLUMN_NAME, self::PPID_PS_COLUMN_NAME, self::STATE_PS_COLUMN_NAME, self::COMMAND_PS_COLUMN_NAME];
        /** @var ?int $expectedLinePartsCount */
        static $expectedLinePartsCount = null;
        if ($expectedLinePartsCount === null) {
            $expectedLinePartsCount = count($expectedFirstLineParts);
        }

        Assert::assertTrue(IterableUtil::getFirstValue($outputLines, /* out */ $firstLine));
        /** @var string $firstLine */
        $firstLineParts = self::splitStringOnWhitespace($firstLine, $expectedLinePartsCount);
        Assert::assertCount($expectedLinePartsCount, $firstLineParts);
        AssertEx::equalLists($expectedFirstLineParts, $firstLineParts);

        /** @var PidToAdditionalDetails $result */
        $result = [];
        foreach (IterableUtil::skipFirst($outputLines) as $outputLine) {
            $currentLineParts = self::splitStringOnWhitespace($outputLine, $expectedLinePartsCount);
            Assert::assertCount($expectedLinePartsCount, $currentLineParts);
            $pid = AssertEx::isNonNegativeInt(NumericUtilForTests::parseStringAsInt($currentLineParts[0]));
            $parentPid = AssertEx::isNonNegativeInt(NumericUtilForTests::parseStringAsInt($currentLineParts[1]));
            $command = $currentLineParts[2];
            ArrayUtilForTests::addAssertingKeyNew($pid, new RunningProcessAdditionalDetails($parentPid, $command), /* ref */ $result);
        }
        return new self($result);
    }

    public static function getForAllInCurrentSession(): self
    {
        Assert::assertFalse(OsUtil::isWindows());

        DebugContext::getCurrentScope(/* out */ $dbgCtx);
        $cmd = 'ps -o pid,ppid,stat,args';
        $dbgCtx->add(compact('cmd'));
        $outputLastLine = exec($cmd, /* out */ $outputLinesAsArray, /* out */ $exitCode);
        $dbgCtx->add(compact('exitCode', 'outputLinesAsArray', 'outputLastLine'));
        Assert::assertSame(0, $exitCode);
        Assert::assertIsString($outputLastLine);

        return self::parsePsCommandOutput($outputLinesAsArray);
    }

    public function doAnyOfProcessesStillExist(): bool
    {
        $latestProcesses = self::getForAllInCurrentSession();
        foreach ($this->getPids() as $pid) {
            if ($latestProcesses->hasPid($pid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private static function splitStringOnWhitespace(string $outputLine, int $partsCountLimit): array
    {
        // Use \s+ to match one or more whitespace characters
        return AssertEx::notFalse(preg_split('/\s+/', $outputLine, /* limit: */ $partsCountLimit, /* flags: */ PREG_SPLIT_NO_EMPTY));
    }

    /**
     * @return iterable<Pid>
     */
    public function getPids(): iterable
    {
        return IterableUtil::keys($this->pidToAdditionalDetails);
    }

    /**
     * @param Pid $pid
     */
    public function hasPid(int $pid): bool
    {
        return array_key_exists($pid, $this->pidToAdditionalDetails);
    }

    /**
     * @param Pid $descendantPid
     *
     * @return iterable<Pid>
     */
    public function iterateAncestorsOf(int $descendantPid): iterable
    {
        $currentPid = $descendantPid;
        while (ArrayUtil::getValueIfKeyExists($currentPid, $this->pidToAdditionalDetails, /* out */ $currentAdditionalDetails)) {
            /** @var RunningProcessAdditionalDetails $currentAdditionalDetails */
            $currentPid = $currentAdditionalDetails->parentPid;
            yield $currentPid;
        }
    }

    /**
     * @param Pid $maybeAncestorPid
     * @param Pid $maybeDescendantPid
     */
    public function isDescendantOf(int $maybeDescendantPid, int $maybeAncestorPid): bool
    {
        return IterableUtil::contains($this->iterateAncestorsOf($maybeDescendantPid), $maybeAncestorPid);
    }

    /**
     * @param Set<Pid> $rootPids
     */
    public function getSubTrees(Set $rootPids): self
    {
        $isisDescendantOfAnyRoot = function (int $maybeDescendantPid) use ($rootPids): bool {
            foreach ($rootPids as $rootPid) {
                if ($this->isDescendantOf($maybeDescendantPid, $rootPid)) {
                    return true;
                }
            }
            return false;
        };

        /** @var PidToAdditionalDetails $result */
        $result = [];
        foreach ($this->pidToAdditionalDetails as $pid => $additionalDetails) {
            if ($rootPids->contains($pid) || $isisDescendantOfAnyRoot($pid)) {
                ArrayUtilForTests::addAssertingKeyNew($pid, $additionalDetails, /* ref */ $result);
            }
        }
        return new self($result);
    }

    /**
     * @param PidToAdditionalDetails $pidToAdditionalDetails
     * @param Pid $pid
     */
    private static function isLeaf(array $pidToAdditionalDetails, int $pid): bool
    {
        foreach ($pidToAdditionalDetails as $additionalDetails) {
            if ($additionalDetails->parentPid === $pid) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param PidToAdditionalDetails $pidToAdditionalDetails
     * @return ?int
     */
    private static function findLeaf(array $pidToAdditionalDetails): ?int
    {
        foreach ($pidToAdditionalDetails as $currentPid => $_) {
            if (self::isLeaf($pidToAdditionalDetails, $currentPid)) {
                return $currentPid;
            }
        }
        return null;
    }

    /**
     * @param PidToAdditionalDetails $pidToAdditionalDetails
     * @return iterable<Pid, RunningProcessAdditionalDetails>
     */
    private static function iterateInTopologicalOrderImpl(array $pidToAdditionalDetails): iterable
    {
        $remainingPidToAdditionalDetails = $pidToAdditionalDetails;
        while (!empty($remainingPidToAdditionalDetails)) {
            $currentPid = AssertEx::notNull(self::findLeaf($remainingPidToAdditionalDetails));
            yield $currentPid => $remainingPidToAdditionalDetails[$currentPid];
            unset($remainingPidToAdditionalDetails[$currentPid]);
        }
    }

    /**
     * @return iterable<Pid, RunningProcessAdditionalDetails>
     */
    public function iterateInTopologicalOrder(): iterable
    {
        return self::iterateInTopologicalOrderImpl($this->pidToAdditionalDetails);
    }

    /**
     * @param PidToDbgDesc $pidToDbgDesc
     */
    public function terminate(array $pidToDbgDesc, bool $force): void
    {
        $logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)->addAllContext(compact('this', 'pidToDbgDesc', 'force'));
        $logDebug = $logger->logDebug(__FUNCTION__);
        $logDebug?->with(__LINE__, 'Entered');

        foreach ($this->iterateInTopologicalOrder() as $pid => $additionalDetails) {
            $logCtx = compact('pid', 'additionalDetails');
            if (ArrayUtil::getValueIfKeyExists($pid, $pidToDbgDesc, /* out */ $dbgDesc)) {
                $logCtx += compact('dbgDesc');
            }

            if (!ProcessUtil::doesProcessExist($pid)) {
                $logDebug?->with(__LINE__, 'Process does not exist anymore - no need to terminate', $logCtx);
                continue;
            }

            $logDebug?->with(__LINE__, 'Executing command to terminate process', $logCtx);
            ProcessUtil::execCommandToTerminateProcess($pid, $force);
        }

        $logDebug?->with(__LINE__, 'Exiting');
    }

    public function waitToExit(string $dbgProcessesSetDesc, int $maxWaitTimeInMicroseconds): bool
    {
        return (new PollingCheck(dbgDesc: $dbgProcessesSetDesc . ' processes to exit', maxWaitTimeInMicroseconds: $maxWaitTimeInMicroseconds))->run(fn() => !$this->doAnyOfProcessesStillExist());
    }

    #[Override]
    public function toLog(LogStreamInterface $stream): void
    {
        $stream->toLogAs($this->pidToAdditionalDetails);
    }

    /**
     * @return Traversable<Pid, RunningProcessAdditionalDetails>
     */
    #[Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->pidToAdditionalDetails);
    }

    /**
     * @inheritDoc
     *
     * @param Pid $offset
     *
     * @return bool
     */
    #[Override]
    public function offsetExists(mixed $offset): bool
    {
        ProcessUtil::assertValidPid($offset); // @phpstan-ignore staticMethod.alreadyNarrowedType
        return self::hasPid($offset);
    }

    /**
     * @inheritDoc
     *
     * @param Pid $offset
     */
    #[Override]
    #[ReturnTypeWillChange]
    public function offsetGet(mixed $offset): RunningProcessAdditionalDetails
    {
        ProcessUtil::assertValidPid($offset); // @phpstan-ignore staticMethod.alreadyNarrowedType
        return $this->pidToAdditionalDetails[$offset];
    }

    /**
     * @inheritDoc
     *
     * @param Pid $offset
     */
    #[Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        Assert::fail(LoggableToString::convertMessageAndContext(self::class . ' is read-only and it does not support setting via ArrayAccess interface', compact('offset', 'value', 'this')));
    }

    #[Override]
    public function offsetUnset(mixed $offset): void
    {
        Assert::fail(LoggableToString::convertMessageAndContext(self::class . ' is read-only and it does not support unsetting via ArrayAccess interface', compact('offset', 'this')));
    }
}
