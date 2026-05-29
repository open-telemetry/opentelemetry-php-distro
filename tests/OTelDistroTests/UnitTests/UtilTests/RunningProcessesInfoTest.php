<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests;

use OTelDistroTests\ComponentTests\Util\RunningProcessAdditionalDetails;
use OTelDistroTests\ComponentTests\Util\RunningProcessesInfo;
use OTelDistroTests\ComponentTests\Util\ProcessUtil;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\FileUtil;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\OsUtil;
use OTelDistroTests\Util\TestCaseBase;
use OTelDistroTests\Util\TextUtilForTests;
use PHPUnit\Exception as PHPUnitExceptionInterface;
use PHPUnit\Framework\Assert;

/**
 * @phpstan-import-type Pid from RunningProcessesInfo
 * @phpstan-import-type PidToAdditionalDetails from RunningProcessesInfo
 */
class RunningProcessesInfoTest extends TestCaseBase
{
    /**
     * @phpstan-param Pid $parentPid
     */
    private static function newAdditionalDetails(int $parentPid, string $commandLine): RunningProcessAdditionalDetails
    {
        return new RunningProcessAdditionalDetails(parentPid: $parentPid, commandLine: $commandLine);
    }

    public function testParsePsCommandListingOutput(): void
    {
        /**
         * @param iterable<string> $outputLines
         * @phpstan-param PidToAdditionalDetails $expectedResult
         */
        $impl = function (iterable $outputLines, array $expectedResult): void {
            /** @var iterable<string> $outputLines */
            /** @var PidToAdditionalDetails $expectedResult */

            DebugContext::getCurrentScope(/* out */ $dbgCtx);

            $actualResult = RunningProcessesInfo::parsePsCommandOutput($outputLines);
            AssertEx::equalScalarLists(array_keys($expectedResult), $actualResult->getPids());
            $dbgCtx->pushSubScope();
            foreach ($expectedResult as $pid => $expectedAdditionalDetails) {
                $dbgCtx->resetTopSubScope(compact('pid', 'expectedAdditionalDetails'));
                Assert::assertTrue($actualResult[$pid]->equals($expectedAdditionalDetails));
            }
            $dbgCtx->popSubScope();
        };

        // No output lines should cause an exception to be thrown
        AssertEx::throws(PHPUnitExceptionInterface::class, fn() => $impl([], []));

        // Output lines has only just the header
        $impl([" \t PID    PPID COMMAND"], []);

        $impl(
            [
                'PID    PPID COMMAND',
                '209280       1 php runResourcesCleaner.php 2>&1 | tee ResourcesCleaner.log',
            ],
            [
                209280 => self::newAdditionalDetails(parentPid: 1, commandLine: 'php runResourcesCleaner.php 2>&1 | tee ResourcesCleaner.log'),
            ],
        );

        $impl(
            [
                'PID    PPID COMMAND',
                "209277  209253 sh -c phpunit -c phpunit_component_tests.xml '--group' 'does_not_require_external_services'",
                '209280       1 php runResourcesCleaner.php 2>&1 | tee ResourcesCleaner.log',
            ],
            [
                209277 => self::newAdditionalDetails(parentPid: 209253, commandLine: "sh -c phpunit -c phpunit_component_tests.xml '--group' 'does_not_require_external_services'"),
                209280 => self::newAdditionalDetails(parentPid: 1, commandLine: 'php runResourcesCleaner.php 2>&1 | tee ResourcesCleaner.log'),
            ],
        );

        /** @phpstan-var string $exampleOutputAsOneString */
        static $exampleOutputAsOneString = <<<'END_OF_STRING_MARKER_4d416bb9_c85c_4df2_9d83_a2d144e79cdc'
                    PID    PPID COMMAND
                 209277  209253 sh -c phpunit -c phpunit_component_tests.xml '--group' 'does_not_require_external_services'
                 209278  209277 php phpunit -c phpunit_component_tests.xml --group does_not_require_external_services
                 209280       1 php runResourcesCleaner.php 2>&1 | tee ResourcesCleaner.log
            END_OF_STRING_MARKER_4d416bb9_c85c_4df2_9d83_a2d144e79cdc;

        $exampleOutputResult = [
            209277 => self::newAdditionalDetails(parentPid: 209253, commandLine: "sh -c phpunit -c phpunit_component_tests.xml '--group' 'does_not_require_external_services'"),
            209278 => self::newAdditionalDetails(parentPid: 209277, commandLine: "php phpunit -c phpunit_component_tests.xml --group does_not_require_external_services"),
            209280 => self::newAdditionalDetails(parentPid: 1, commandLine: "php runResourcesCleaner.php 2>&1 | tee ResourcesCleaner.log"),
        ];

        $impl(TextUtilForTests::iterateLines($exampleOutputAsOneString), $exampleOutputResult);
    }

    private function getProcessCommandLine(int $pid): string
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $procCmdLineFileContents = FileUtil::getFileContents("/proc/$pid/cmdline");
        // Arguments in /proc/pid/cmdline are null-separated (\0)
        return trim(str_replace("\0", ' ', $procCmdLineFileContents));
    }

    public function testGetProcessSubTreeAdditionalDetails(): void
    {
        if (OsUtil::isWindows()) {
            self::dummyAssert();
            return;
        }

        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $myPid = ProcessUtil::getCurrentPid();
        $dbgCtx->add(compact('myPid'));
        self::assertSame(posix_getpid(), $myPid);
        $parentPid = posix_getppid();
        $dbgCtx->add(compact('parentPid'));
        $actualRunningProcesses = RunningProcessesInfo::getForAllInCurrentSession();
        $dbgCtx->add(compact('actualRunningProcesses'));
        $actualMyAdditionalDetails = $actualRunningProcesses[$myPid];
        $dbgCtx->add(compact('actualMyAdditionalDetails'));
        self::assertSame($parentPid, $actualMyAdditionalDetails->parentPid);
        global $argv;
        $expectedMyCommandLineSuffix = implode(' ', $argv);
        $dbgCtx->add(compact('expectedMyCommandLineSuffix'));
        $expectedMyCommandLine = self::getProcessCommandLine($myPid);
        $dbgCtx->add(compact('expectedMyCommandLine'));
        self::assertStringEndsWith($expectedMyCommandLineSuffix, $expectedMyCommandLine);
        self::assertSame($expectedMyCommandLine, $actualMyAdditionalDetails->commandLine);

        $expectedParentCommandLine = self::getProcessCommandLine($parentPid);
        /** @var PidToAdditionalDetails $actualRunningProcesses */
        $actualParentAdditionalDetails = AssertEx::arrayHasKey($parentPid, $actualRunningProcesses);
        $dbgCtx->add(compact('actualParentAdditionalDetails'));
        self::assertSame($expectedParentCommandLine, $actualParentAdditionalDetails->commandLine);

        self::assertTrue($actualRunningProcesses->isDescendantOf($myPid, $parentPid));
    }

    public function testIterateAncestorsOf(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        /**
         * @param array<Pid, Pid> $pidToParentPid
         * @phpstan-param Pid $pid
         * @phpstan-param list<Pid> $expectedResult
         */
        $impl = function (array $pidToParentPid, int $pid, array $expectedResult): void {
            /** @var array<Pid, Pid> $pidToParentPid */
            /** @var Pid $pid */
            /** @var list<Pid> $expectedResult */
            /** @var PidToAdditionalDetails $processesInfos */
            $processesInfos = new RunningProcessesInfo(array_map(fn($parentPid) => self::newAdditionalDetails($parentPid, 'dummy cmd'), $pidToParentPid));
            $actualResult = IterableUtil::toList($processesInfos->iterateAncestorsOf($pid));
            AssertEx::equalScalarLists($expectedResult, $actualResult);
        };

        $impl([], 123, []);
        $impl([111 => 11], 1, []);
        $impl([111 => 11], 11, []);
        $impl([111 => 11], 111, [11]);
        $impl([111 => 11], 11, []);
        $impl([111 => 11, 11 => 1], 111, [11, 1]);
        $impl([111 => 11, 11 => 1], 11, [1]);
        $impl([111 => 11, 11 => 1], 1, []);
        $impl([122 => 12, 121 => 12, 111 => 11, 12 => 1, 11 => 1], 111, [11, 1]);
        $impl([122 => 12, 121 => 12, 111 => 11, 12 => 1, 11 => 1], 11, [1]);
        $impl([122 => 12, 121 => 12, 111 => 11, 12 => 1, 11 => 1], 12, [1]);
        $impl([122 => 12, 121 => 12, 111 => 11, 12 => 1, 11 => 1], 111, [11, 1]);
        $impl([122 => 12, 121 => 12, 111 => 11, 12 => 1, 11 => 1], 121, [12, 1]);
        $impl([122 => 12, 121 => 12, 111 => 11, 12 => 1, 11 => 1], 122, [12, 1]);
    }
}
